<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Http\ApiResponse;
use App\Support\Tenancy\OrgContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotence sur les mutations critiques.                             [D-11]
 *
 * Une caisse qui perd le réseau au milieu d'un encaissement réessaie. Sans
 * cette garde, le client est débité deux fois pour une seule vente. La clé
 * vient du client — elle est stable d'un essai à l'autre, contrairement à
 * tout ce que le serveur pourrait inventer.
 *
 * Quatre cas, et un seul est un succès :
 *   clé inconnue                      → on traite, on mémorise la réponse
 *   clé connue, même requête          → on rejoue la réponse mémorisée
 *   clé connue, requête DIFFÉRENTE    → 422, c'est un bug du client et le
 *                                       masquer ferait disparaître une vente
 *   clé connue, encore en cours       → 409, la caisse réessaiera
 */
final class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || trim($key) === '') {
            return ApiResponse::error(
                'IDEMPOTENCY_KEY_REQUIRED',
                'L\'en-tête Idempotency-Key est obligatoire sur cette opération.',
                400,
            );
        }

        $organizationId = app(OrgContext::class)->organizationId();
        $fingerprint = $this->fingerprint($request);

        $existing = DB::table('idempotency_keys')
            ->where('organization_id', $organizationId)
            ->where('key', $key)
            ->first();

        if ($existing !== null) {
            if ($existing->request_fingerprint !== $fingerprint) {
                return ApiResponse::error(
                    'IDEMPOTENCY_KEY_REUSED',
                    'Cette clé a déjà servi pour une requête différente.',
                    422,
                );
            }

            if ($existing->status === 'IN_PROGRESS') {
                return ApiResponse::error(
                    'REQUEST_IN_FLIGHT',
                    'Cette opération est déjà en cours de traitement.',
                    409,
                );
            }

            return response()->json(
                json_decode((string) $existing->response_body, true, 512, JSON_THROW_ON_ERROR),
                $existing->response_status ?? 200,
                ['Idempotent-Replay' => 'true'],
            );
        }

        // L'insertion EST le verrou : deux requêtes simultanées portant la même
        // clé se disputent la clé primaire, et la perdante repart au début de
        // cette méthode où elle trouve la ligne IN_PROGRESS.
        try {
            DB::table('idempotency_keys')->insert([
                'organization_id' => $organizationId,
                'key' => $key,
                'request_fingerprint' => $fingerprint,
                'status' => 'IN_PROGRESS',
                'locked_at' => now(),
                'created_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return ApiResponse::error(
                'REQUEST_IN_FLIGHT',
                'Cette opération est déjà en cours de traitement.',
                409,
            );
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 500) {
            // Une panne serveur ne doit pas graver un échec : la caisse doit
            // pouvoir refaire sa tentative avec la même clé.
            DB::table('idempotency_keys')
                ->where('organization_id', $organizationId)
                ->where('key', $key)
                ->delete();

            return $response;
        }

        DB::table('idempotency_keys')
            ->where('organization_id', $organizationId)
            ->where('key', $key)
            ->update([
                'status' => 'COMPLETED',
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'completed_at' => now(),
            ]);

        return $response;
    }

    /**
     * L'empreinte couvre la route ET le corps : la même clé sur deux ventes
     * différentes est une erreur, pas un doublon.
     */
    private function fingerprint(Request $request): string
    {
        $payload = $request->all();
        $this->sortDeep($payload);

        return hash('sha256', implode('|', [
            $request->method(),
            $request->path(),
            json_encode($payload, JSON_THROW_ON_ERROR),
        ]));
    }

    private function sortDeep(array &$data): void
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sortDeep($value);
            }
        }

        // Les clés numériques gardent leur ordre : dans un panier, la liste
        // des lignes est signifiante.
        if (! array_is_list($data)) {
            ksort($data);
        }
    }
}

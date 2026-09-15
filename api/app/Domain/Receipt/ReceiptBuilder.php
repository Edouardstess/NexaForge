<?php

declare(strict_types=1);

namespace App\Domain\Receipt;

use App\Domain\Shared\Money;
use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Le ticket de caisse, en texte brut d'abord.
 *
 * Construit sur le serveur et non dans la caisse : le ticket est une pièce
 * que le commerçant peut devoir montrer, et deux caisses de versions
 * différentes ne doivent pas imprimer deux mises en page.
 *
 * La largeur est en CARACTÈRES, pas en millimètres : une imprimante
 * thermique compte des colonnes. 32 pour du 58 mm, 42 pour du 80 mm.
 */
final class ReceiptBuilder
{
    public function __construct(
        private readonly int $width = 42,
        private readonly string $timezone = 'America/Port-au-Prince',
    ) {}

    public function text(Order $order, array $context = []): string
    {
        $lines = [];

        $lines[] = $this->centre(strtoupper($context['organization'] ?? ''));
        if (! empty($context['location'])) {
            $lines[] = $this->centre($context['location']);
        }
        if (! empty($context['address'])) {
            $lines[] = $this->centre($context['address']);
        }
        if (! empty($context['phone'])) {
            $lines[] = $this->centre('Tel ' . $context['phone']);
        }

        $lines[] = '';
        $lines[] = $this->rule();
        $lines[] = $this->pair($order->order_number,
            Carbon::parse($order->taken_at)->timezone($this->timezone)->format('d/m/Y H:i'));

        if (! empty($context['cashier'])) {
            $lines[] = 'Kesye : ' . $context['cashier'];
        }
        if ($order->origin === 'OFFLINE') {
            // Le client doit pouvoir comprendre pourquoi l'heure du ticket
            // ne colle pas avec l'heure d'enregistrement.
            $lines[] = 'Vant fet san koneksyon';
        }

        $lines[] = $this->rule();

        foreach ($order->items as $item) {
            $quantity = rtrim(rtrim($item->quantity, '0'), '.');
            $lines[] = $this->clip($item->description);
            $lines[] = $this->pair(
                '  ' . $quantity . ' x ' . $this->amount($item->unit_price_minor),
                $this->amount($item->line_total_minor),
            );

            if ($item->discount_minor > 0) {
                $lines[] = $this->pair('  Remiz', '-' . $this->amount($item->discount_minor));
            }
        }

        $lines[] = $this->rule();
        $lines[] = $this->pair('Sou-total', $this->amount($order->subtotal_minor));

        if ($order->discount_minor > 0) {
            $lines[] = $this->pair('Remiz', '-' . $this->amount($order->discount_minor));
        }
        if ($order->tax_minor > 0) {
            $lines[] = $this->pair('TCA', $this->amount($order->tax_minor));
        }

        $lines[] = $this->pair('TOTAL ' . $order->currency, $this->amount($order->total_minor));
        $lines[] = '';

        foreach ($order->payments as $payment) {
            $label = $payment->method . ' ' . $payment->currency;
            $lines[] = $this->pair($label, $this->amount($payment->amount_minor));

            // Le taux figure sur le ticket : c'est la preuve de ce qui a été
            // appliqué le jour de la vente, si le client revient.      [D-04]
            if ($payment->currency !== $order->currency) {
                $lines[] = $this->pair(
                    '  @ ' . rtrim(rtrim((string) $payment->fx_rate, '0'), '.'),
                    $this->amount($payment->base_amount_minor),
                );
            }
            if (($payment->change_minor ?? 0) > 0) {
                $lines[] = $this->pair('  Monnen', $this->amount($payment->change_minor));
            }
        }

        if ($order->refunded_minor > 0) {
            $lines[] = '';
            $lines[] = $this->pair('RANBOUSE', $this->amount($order->refunded_minor));
        }

        $lines[] = '';
        $lines[] = $this->centre('Mesi anpil !');
        $lines[] = $this->centre($context['footer'] ?? 'Kenbe resi a pou echanj');

        return implode("\n", $lines) . "\n";
    }

    /**
     * Le même ticket en octets ESC/POS.
     *
     * Volontairement limité aux commandes qu'aucune imprimante thermique ne
     * refuse : init, gras, centrage, coupe. Les extensions propriétaires
     * marchent sur un modèle et bloquent le suivant.
     */
    public function escpos(Order $order, array $context = []): string
    {
        $esc = "\x1B";
        $gs = "\x1D";

        $out = $esc . '@';                       // initialiser
        $out .= $esc . 'a' . "\x01";             // centrer
        $out .= $esc . 'E' . "\x01";             // gras
        $out .= strtoupper($context['organization'] ?? '') . "\n";
        $out .= $esc . 'E' . "\x00";             // gras off
        $out .= $esc . 'a' . "\x00";             // aligner à gauche

        // Le corps est déjà mis en page en colonnes : l'imprimante n'a qu'à
        // le sortir tel quel.
        $body = $this->text($order, $context);
        $body = substr($body, strpos($body, "\n") + 1);   // l'en-tête est déjà sorti

        $out .= $this->toCp437($body);
        $out .= "\n\n\n";
        $out .= $gs . 'V' . "\x42" . "\x00";     // couper le papier

        return $out;
    }

    private function amount(int $minor): string
    {
        return number_format($minor / 100, 2, ',', ' ');
    }

    private function rule(): string
    {
        return str_repeat('-', $this->width);
    }

    private function centre(string $text): string
    {
        $text = $this->clip($text);
        $pad = intdiv($this->width - mb_strlen($text), 2);

        return str_repeat(' ', max(0, $pad)) . $text;
    }

    /** Libellé à gauche, montant à droite, sur une seule ligne. */
    private function pair(string $left, string $right): string
    {
        $left = $this->clip($left, $this->width - mb_strlen($right) - 1);
        $gap = $this->width - mb_strlen($left) - mb_strlen($right);

        return $left . str_repeat(' ', max(1, $gap)) . $right;
    }

    private function clip(string $text, ?int $width = null): string
    {
        $width ??= $this->width;

        return mb_strlen($text) > $width ? mb_substr($text, 0, $width) : $text;
    }

    /**
     * Les imprimantes thermiques bon marché sortent du CP437 : un « è » en
     * UTF-8 y devient deux caractères illisibles. On translittère plutôt que
     * d'imprimer des hiéroglyphes sur le ticket d'un client.
     */
    private function toCp437(string $text): string
    {
        $map = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ò' => 'o', 'À' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ô' => 'O',
            'Ò' => 'O', 'Ç' => 'C', 'Ù' => 'U', 'Î' => 'I', '’' => "'", '—' => '-', '·' => '-',
            "\u{202F}" => ' ', "\u{00A0}" => ' ',
        ];

        return strtr($text, $map);
    }
}

<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Streaming de archivos CSV a la respuesta HTTP (no escribe a disco).
 *
 * Cada celda se neutraliza contra CSV/Excel formula injection (=, +, -, @, tab,
 * CR/LF) prefijando con apóstrofo, salvo numéricos puros. Mismo patrón que
 * IEM_CSV del plugin de Inventario.
 */
class FIN_CSV
{
    public static function safe_cell($value)
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $s = (string) $value;
        if ($s === '' || is_numeric($s)) {
            return $s;
        }
        $first = $s[0];
        if ($first === '=' || $first === '+' || $first === '-' || $first === '@'
            || $first === "\t" || $first === "\r" || $first === "\n") {
            return "'" . $s;
        }
        return $s;
    }

    private static function safe_row(array $row)
    {
        return array_map([__CLASS__, 'safe_cell'], $row);
    }

    private static function open($filename)
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM Excel
        return $out;
    }

    /**
     * Stream del listado de movimientos.
     *
     * @param string $filename
     * @param array  $movements      Filas de FIN_Movements::query().
     * @param array  $accounts_map   [account_id => name]
     * @param array  $categories_map [category_id => name]
     */
    public static function stream_movements($filename, array $movements, array $accounts_map = [], array $categories_map = [])
    {
        $out = self::open($filename);

        // "Saldo cuenta (al asentar)" = `balance_after`: el saldo que tenía la
        // cuenta cuando se INSERTÓ el asiento. NO es el saldo corrido por fecha
        // que muestra el listado (el ledger va antedatado, así que los dos órdenes
        // no coinciden); es el dato de auditoría del asiento. Ver FIN_Movements.
        fputcsv($out, self::safe_row([
            'ID', 'Fecha', 'Cuenta', 'Tipo', 'Categoría', 'Ingreso', 'Egreso',
            'Saldo cuenta (al asentar)', 'Descripción', 'Referencia', 'Anulado',
        ]));

        foreach ($movements as $m) {
            $cat = '';
            if (!empty($m['category_id']) && isset($categories_map[(int) $m['category_id']])) {
                $cat = $categories_map[(int) $m['category_id']];
            }
            $ingreso = ($m['direction'] === 'I') ? (float) $m['amount'] : '';
            $egreso  = ($m['direction'] === 'O') ? (float) $m['amount'] : '';
            $ref     = trim(((string) ($m['ref_table'] ?? '')) . ' ' . ((string) ($m['ref_code'] ?? '')));

            fputcsv($out, self::safe_row([
                (int) $m['id'],
                (string) $m['movement_date'],
                $accounts_map[(int) $m['account_id']] ?? ('#' . (int) $m['account_id']),
                FIN_Movements::type_label($m['type']),
                $cat,
                $ingreso,
                $egreso,
                (float) $m['balance_after'],
                (string) $m['description'],
                $ref,
                !empty($m['reversed_at']) ? 'Sí' : '',
            ]));
        }

        fclose($out);
        exit;
    }

    /**
     * Stream genérico de un reporte tabular: cabecera + filas ya formateadas
     * como arrays planos.
     *
     * @param string $filename
     * @param array  $header  Lista de títulos de columna.
     * @param array  $rows    Lista de arrays (una por fila).
     */
    public static function stream_report($filename, array $header, array $rows)
    {
        $out = self::open($filename);
        fputcsv($out, self::safe_row($header));
        foreach ($rows as $row) {
            fputcsv($out, self::safe_row(array_values($row)));
        }
        fclose($out);
        exit;
    }
}

<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Streaming de archivos CSV a la respuesta HTTP.
 * No escribe archivos en disco.
 *
 * Cada celda se neutraliza contra CSV/Excel formula injection
 * (`=`, `+`, `@`, tab, CR/LF) prefijando con apóstrofo. Los valores
 * numéricos puros se dejan intactos para preservar fórmulas en Excel.
 */
class IEM_CSV
{
    /**
     * @param string $filename       Nombre del archivo descargado.
     * @param array  $rows           Filas de IEM_Collector::collect().
     * @param bool   $include_conteo Si true, agrega columnas Conteo + Estado.
     * @param array  $conteos        [item_id => valor ingresado por usuario].
     */
    public static function stream($filename, array $rows, $include_conteo = false, array $conteos = [])
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM Excel

        // Orden de columnas v3.6+: SKU, Producto, Stock, Sucursal, Categoría, ...
        $header = $include_conteo
            ? ['SKU', 'Producto', 'Stock', 'Conteo', 'Estado', 'Sucursal', 'Categoría', 'Precio', 'Costo']
            : ['SKU', 'Producto', 'Stock', 'Sucursal', 'Categoría', 'Precio', 'Costo'];

        fputcsv($out, self::safe_row($header));

        foreach ($rows as $r) {
            if ($include_conteo) {
                $raw = isset($conteos[$r['item_id']]) ? trim((string) $conteos[$r['item_id']]) : '';
                if ($raw === '') {
                    $conteo_out = '';
                    $estado     = 'Sin conteo';
                } else {
                    $conteo_out = (int) $raw;
                    $estado     = ($conteo_out === (int) $r['stock']) ? 'OK' : 'Revisar';
                }
                $row = [
                    $r['sku'], $r['name'], $r['stock'],
                    $conteo_out, $estado,
                    $r['sucursal_name'], $r['category'], $r['price'], $r['cost'],
                ];
            } else {
                $row = [
                    $r['sku'], $r['name'], $r['stock'],
                    $r['sucursal_name'], $r['category'], $r['price'], $r['cost'],
                ];
            }
            fputcsv($out, self::safe_row($row));
        }

        fclose($out);
        exit;
    }

    /**
     * Neutraliza el contenido de una celda contra formula injection.
     * Numéricos puros (incluido negativos como "-5") se dejan tal cual
     * para que Excel los siga tratando como número.
     *
     * @param mixed $value
     * @return mixed
     */
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

    /**
     * Stream de un conteo persistido (sesión). Usa filas de iem_count_lines.
     *
     * Se eliminó el bloque inicial "# Período / # Sucursal / # Estado / # Creado / # Cerrado"
     * porque tenía 2 columnas mientras las filas de datos tenían 8, lo que desencajaba
     * toda la tabla al abrir el CSV en Excel/LibreOffice. La metadata ahora se incluye
     * como columnas redundantes en cada fila (Período / Sucursal / Estado sesión / Creado / Cerrado).
     *
     * @param string $filename       Nombre del archivo.
     * @param array  $session_lines  Filas de iem_count_lines.
     * @param array  $session_meta   Cabecera de la sesión.
     * @param array  $sucursales_map Mapa slug => nombre legible (para mostrar nombre, no slug).
     */
    public static function stream_session($filename, array $session_lines, array $session_meta = [], array $sucursales_map = [])
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $period    = $session_meta['period']        ?? '';
        $suc_slug  = $session_meta['sucursal_slug'] ?? '';
        $suc_name  = $sucursales_map[$suc_slug] ?? $suc_slug;
        $status    = $session_meta['status']        ?? '';
        $created   = $session_meta['created_at']    ?? '';
        $closed    = $session_meta['closed_at']     ?? '';

        // Orden de columnas v3.6+: SKU, Producto, Stock al conteo, Sucursal,
        // Categoría primero. Metadata redundante de la sesión al final.
        // v3.10+: columnas Extra (Sí/No) y Notas para distinguir filas ad-hoc
        // agregadas por el contador (productos fuera del listado original).
        fputcsv($out, self::safe_row([
            'SKU', 'Producto', 'Stock al conteo', 'Sucursal', 'Categoría',
            'Conteo', 'Estado línea', 'Extra', 'Notas', 'Actualizado',
            'Período', 'Estado sesión', 'Creado sesión', 'Cerrado sesión',
        ]));

        foreach ($session_lines as $l) {
            $row_suc = $sucursales_map[$l['sucursal_slug']] ?? $l['sucursal_slug'];
            $is_extra = !empty($l['is_extra']);
            fputcsv($out, self::safe_row([
                $l['sku'], $l['name'], (int) $l['stock_at_count'], $row_suc, $l['category'],
                $l['counted_qty'] !== null ? (int) $l['counted_qty'] : '',
                $l['status_at_count'],
                $is_extra ? 'Sí' : 'No',
                $l['notes'] ?? '',
                $l['updated_at'],
                $period, $status, $created, $closed,
            ]));
        }

        fclose($out);
        exit;
    }

    /**
     * Stream del registro de mermas.
     *
     * @param string $filename
     * @param array  $mermas          Filas de iem_mermas.
     * @param array  $tipos_map       IEM_Mermas::get_tipos() (slug => label).
     * @param array  $sucursales_map  Mapa slug => nombre legible.
     */
    public static function stream_mermas($filename, array $mermas, array $tipos_map = [], array $sucursales_map = [])
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Orden de columnas v3.8+: SKU, Producto, Sucursal, ... + Costo/Subtotal.
        fputcsv($out, self::safe_row([
            'SKU', 'Producto', 'Sucursal', 'Tipo producto',
            'Fecha', 'Cantidad', 'Costo unitario', 'Subtotal',
            'Tipo', 'Descontó WC', 'Usuario', 'Sesión',
            'Retornada', 'Fecha retorno', 'Retornada por',
        ]));

        $total_qty = 0;
        $total_sub = 0.0;
        foreach ($mermas as $m) {
            $user        = (int) $m['user_id'] ? get_user_by('id', (int) $m['user_id']) : null;
            $ret_user    = !empty($m['returned_by']) ? get_user_by('id', (int) $m['returned_by']) : null;
            $tipo        = isset($tipos_map[$m['tipo']]) ? $tipos_map[$m['tipo']] : $m['tipo'];
            $returned    = !empty($m['returned_at']);
            $suc_name    = $sucursales_map[$m['sucursal_slug']] ?? $m['sucursal_slug'];
            $is_variant  = (int) $m['parent_id'] !== 0 && (int) $m['parent_id'] !== (int) $m['item_id'];
            $tipo_prod   = $is_variant ? 'Variación' : 'Simple';
            $qty         = (int) $m['qty'];
            $unit_cost   = (isset($m['cost_at_register']) && $m['cost_at_register'] !== null)
                ? (float) $m['cost_at_register'] : null;
            $subtotal    = $unit_cost !== null ? round($unit_cost * $qty, 2) : null;

            $total_qty += $qty;
            if ($subtotal !== null) $total_sub += $subtotal;

            fputcsv($out, self::safe_row([
                $m['sku'], $m['name'], $suc_name, $tipo_prod,
                $m['created_at'], $qty,
                $unit_cost !== null ? number_format($unit_cost, 2, '.', '') : '',
                $subtotal  !== null ? number_format($subtotal,  2, '.', '') : '',
                $tipo,
                !empty($m['decremented_wc']) ? 'Sí' : 'No',
                $user ? $user->display_name : '#' . (int) $m['user_id'],
                $m['session_id'] ? (int) $m['session_id'] : '',
                $returned ? 'Sí' : 'No',
                $returned ? $m['returned_at'] : '',
                $returned ? ($ret_user ? $ret_user->display_name : '#' . (int) $m['returned_by']) : '',
            ]));
        }

        // Fila de totales (separador vacío + resumen). Excel la ve como una
        // fila normal con texto en la primera columna y números a la derecha.
        fputcsv($out, []);
        fputcsv($out, self::safe_row([
            'TOTAL', '', '', '', '', $total_qty, '', number_format($total_sub, 2, '.', ''),
        ]));

        fclose($out);
        exit;
    }
}

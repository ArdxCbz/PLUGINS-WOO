<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Catálogo de monedas (2.3+). Multi-moneda con SEPARACIÓN por moneda (no se
 * consolida): cada cuenta tiene su moneda y los reportes/tesorería subtotalizan
 * por moneda. La moneda BASE (Bs/BOB) es fija (TC = 1, no se borra).
 *
 * Se persiste en la opción `fin_currencies` como mapa:
 *   [ 'BOB' => ['symbol'=>'Bs','name'=>'Boliviano','rate'=>1.0], ... ]
 * donde `rate` es el TC POR DEFECTO = cuántos Bs vale 1 unidad de esa moneda
 * (solo se usa para PRECARGAR el campo de tipo de cambio en los formularios; el
 * TC efectivo de cada movimiento/traspaso se guarda en el propio movimiento).
 */
class FIN_Currencies
{
    const OPT       = 'fin_currencies';
    const BASE_CODE = 'BOB';

    /** Semilla por defecto: Boliviano (base) + Dólar. */
    public static function defaults()
    {
        return [
            'BOB' => ['symbol' => 'Bs', 'name' => 'Boliviano',            'rate' => 1.0],
            'USD' => ['symbol' => '$',  'name' => 'Dólar estadounidense', 'rate' => 6.96],
        ];
    }

    /** Catálogo completo [code => ['symbol','name','rate']]. Garantiza la base. */
    public static function all()
    {
        $v = get_option(self::OPT, null);
        if (!is_array($v) || empty($v)) {
            $v = self::defaults();
        }
        // La base SIEMPRE existe y su TC es 1.
        if (!isset($v[self::BASE_CODE]) || !is_array($v[self::BASE_CODE])) {
            $d = self::defaults();
            $v[self::BASE_CODE] = $d[self::BASE_CODE];
        }
        $v[self::BASE_CODE]['rate'] = 1.0;

        $out = [];
        foreach ($v as $code => $row) {
            $code = strtoupper((string) $code);
            $out[$code] = [
                'symbol' => (string) ($row['symbol'] ?? $code),
                'name'   => (string) ($row['name'] ?? $code),
                'rate'   => max(0.0, (float) ($row['rate'] ?? 0)),
            ];
        }
        return $out;
    }

    public static function codes()
    {
        return array_keys(self::all());
    }

    public static function exists($code)
    {
        $code = strtoupper(trim((string) $code));
        return $code !== '' && isset(self::all()[$code]);
    }

    public static function get($code)
    {
        $all  = self::all();
        $code = strtoupper(trim((string) $code));
        return $all[$code] ?? null;
    }

    public static function base_code()
    {
        return self::BASE_CODE;
    }

    public static function is_base($code)
    {
        return strtoupper(trim((string) $code)) === self::BASE_CODE;
    }

    /** Símbolo de una moneda (o el propio código si no existe). */
    public static function symbol($code)
    {
        $row = self::get($code);
        return $row ? $row['symbol'] : strtoupper(trim((string) $code));
    }

    public static function name($code)
    {
        $row = self::get($code);
        return $row ? $row['name'] : strtoupper(trim((string) $code));
    }

    /** TC por defecto (Bs por 1 unidad). Base = 1. */
    public static function rate($code)
    {
        if (self::is_base($code)) {
            return 1.0;
        }
        $row = self::get($code);
        return $row ? (float) $row['rate'] : 0.0;
    }

    /**
     * Crea o actualiza una moneda.
     *   $args: code, symbol, name, rate
     * La base no puede cambiar su TC (siempre 1) ni su código.
     *
     * @return string|WP_Error código normalizado.
     */
    public static function save(array $args)
    {
        $code = strtoupper(trim((string) ($args['code'] ?? '')));
        if (!preg_match('/^[A-Z]{2,5}$/', $code)) {
            return new WP_Error('fin_cur_code', 'El código debe tener de 2 a 5 letras (ej. USD, BOB, EUR).');
        }
        $symbol = trim((string) ($args['symbol'] ?? ''));
        $name   = trim((string) ($args['name'] ?? ''));
        if ($symbol === '') {
            return new WP_Error('fin_cur_symbol', 'El símbolo es obligatorio (ej. $, Bs, €).');
        }
        if ($name === '') {
            return new WP_Error('fin_cur_name', 'El nombre es obligatorio.');
        }
        if (mb_strlen($symbol) > 8) {
            return new WP_Error('fin_cur_symbol_long', 'El símbolo supera 8 caracteres.');
        }
        if (mb_strlen($name) > 60) {
            return new WP_Error('fin_cur_name_long', 'El nombre supera 60 caracteres.');
        }

        $is_base = ($code === self::BASE_CODE);
        $rate    = $is_base ? 1.0 : round((float) ($args['rate'] ?? 0), 6);
        if (!$is_base && $rate <= 0) {
            return new WP_Error('fin_cur_rate', 'El tipo de cambio por defecto debe ser mayor a 0.');
        }

        $all = self::all();
        $all[$code] = ['symbol' => $symbol, 'name' => $name, 'rate' => $rate];
        update_option(self::OPT, $all, false);
        return $code;
    }

    /**
     * Elimina una moneda. No se puede borrar la base ni una moneda en uso por
     * alguna cuenta (preserva integridad del histórico).
     *
     * @return true|WP_Error
     */
    public static function delete($code)
    {
        $code = strtoupper(trim((string) $code));
        if ($code === self::BASE_CODE) {
            return new WP_Error('fin_cur_base', 'La moneda base no se puede eliminar.');
        }
        if (!self::exists($code)) {
            return new WP_Error('fin_cur_404', 'Moneda no encontrada.');
        }
        if (class_exists('FIN_Accounts') && FIN_Accounts::currency_in_use($code)) {
            return new WP_Error('fin_cur_in_use',
                'No se puede eliminar: hay cuentas que usan esta moneda. Desactívalas o cámbialas primero.');
        }
        $all = self::all();
        unset($all[$code]);
        update_option(self::OPT, $all, false);
        return true;
    }
}

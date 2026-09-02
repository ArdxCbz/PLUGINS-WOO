<?php
/**
 * Banco de pruebas del ensamblado de VCV_Query, sin WordPress.
 * Stubs mínimos + Reflection sobre los métodos privados.
 */

define('ABSPATH', true);

// ── Stubs de WordPress ───────────────────────────────────────────────────
function is_wp_error($t) { return false; }
/**
 * Stub de get_the_title() que devuelve lo mismo que WordPress: el título ya
 * pasado por `wptexturize`, o sea con las entidades HTML puestas.
 */
function get_the_title($id) {
    $titulos = [
        'texturizado' => 'SMARTWATCH B22 &#8211; ANDROID 8.1 &#8211; 4G(4+64GB)',
        'ampersand'   => 'Tom &amp; Jerry',
        'limpio'      => 'RELOJ ANALOGICO DOM 1345',
    ];
    return $titulos[$id] ?? '';
}
function taxonomy_exists($t) { return in_array($t, ['pa_sucursal', 'pa_color'], true); }
function get_terms($args) {
    if ($args['taxonomy'] === 'pa_sucursal') {
        return [
            (object) ['slug' => 'sucursal-cbba-stock', 'name' => 'Cochabamba'],
            (object) ['slug' => 'sucursal-scz-stock',  'name' => 'Santa Cruz'],
            (object) ['slug' => 'sucursal-lpz-stock',  'name' => 'La Paz'],
            (object) ['slug' => 'sucursal-tarija',     'name' => 'Tarija Centro'], // sucursal nueva
        ];
    }
    if ($args['taxonomy'] === 'pa_color') {
        return [
            (object) ['slug' => 'negro',       'name' => 'Negro'],
            (object) ['slug' => 'azul-marino', 'name' => 'Azul Marino'],
        ];
    }
    return [];
}

require_once 'F:/VENTOVA/produccion/complementos/ventova-catalogo-vendedor/includes/class-vcv-sucursales.php';
require_once 'F:/VENTOVA/produccion/complementos/ventova-catalogo-vendedor/includes/class-vcv-query.php';

// ── Utilidades de test ───────────────────────────────────────────────────
$pass = 0; $fail = 0;
function check($label, $actual, $expected) {
    global $pass, $fail;
    $ok = ($actual === $expected);
    if ($ok) { $pass++; echo "  OK   $label\n"; }
    else {
        $fail++;
        echo "  FAIL $label\n";
        echo "       esperado: " . var_export($expected, true) . "\n";
        echo "       obtenido: " . var_export($actual, true) . "\n";
    }
}

function call_private($method, array $args) {
    $r = new ReflectionMethod('VCV_Query', $method);
    $r->setAccessible(true);
    return $r->invokeArgs(null, $args);
}

function build_variable(array $vars, $is_tienda, $include_empty = false) {
    $row = ['id'=>1,'title'=>'X','sku'=>'','permalink'=>'','thumb_id'=>0,
            'type'=>'variable','price_min'=>null,'price_max'=>null,
            'stock_total'=>0,'lines'=>[]];
    $r = new ReflectionMethod('VCV_Query', 'build_variable_row');
    $r->setAccessible(true);
    $args = [&$row, $vars, $is_tienda, VCV_Sucursales::map(), VCV_Sucursales::scz_slug(),
             ['include_empty_lines' => $include_empty]];
    $r->invokeArgs(null, $args);
    return $row;
}

function build_simple(array $meta, $is_tienda, $include_empty = false) {
    $row = ['id'=>2,'title'=>'Y','sku'=>'SIMPLE-1','permalink'=>'','thumb_id'=>0,
            'type'=>'simple','price_min'=>null,'price_max'=>null,
            'stock_total'=>0,'lines'=>[]];
    $r = new ReflectionMethod('VCV_Query', 'build_simple_row');
    $r->setAccessible(true);
    $args = [&$row, $meta, $is_tienda, VCV_Sucursales::scz_slug(),
             ['include_empty_lines' => $include_empty]];
    $r->invokeArgs(null, $args);
    return $row;
}

$map = VCV_Sucursales::map();
$scz = VCV_Sucursales::scz_slug();

echo "\n=== 1. Adaptador de sucursales (sin plugin de inventario) ===\n";
check('SCZ se resuelve por nombre, no por constante', $scz, 'sucursal-scz-stock');
check('mapa slug -> nombre en mayusculas', $map['sucursal-cbba-stock'], 'COCHABAMBA');
check('categoria TIENDA fallback', VCV_Sucursales::tienda_cat_slug(), 'tienda');
check('badge conocido CBBA', VCV_Sucursales::badge('sucursal-cbba-stock')['label'], 'CBBA');
check('badge sucursal nueva -> iniciales', VCV_Sucursales::badge('sucursal-tarija')['label'], 'TC');
check('badge sin sucursal', VCV_Sucursales::badge('')['label'], 'S/S');
check('color slug -> nombre', VCV_Sucursales::color_name('azul-marino'), 'Azul Marino');
check('color desconocido humanizado', VCV_Sucursales::color_name('rojo-vino'), 'Rojo Vino');

// El color de una sucursal nueva debe ser estable entre llamadas.
check('badge sucursal nueva es estable',
    VCV_Sucursales::badge('sucursal-tarija')['bg'],
    VCV_Sucursales::badge('sucursal-tarija')['bg']);

echo "\n=== 2. Regla de sucursal (resolve_sucursal_slug) ===\n";
check('variacion con slug valido',
    call_private('resolve_sucursal_slug', ['sucursal-lpz-stock', false, $map, $scz]),
    'sucursal-lpz-stock');
check('variacion sin sucursal + padre en TIENDA -> SCZ',
    call_private('resolve_sucursal_slug', ['', true, $map, $scz]),
    'sucursal-scz-stock');
check('variacion sin sucursal + padre fuera de TIENDA -> vacio',
    call_private('resolve_sucursal_slug', ['', false, $map, $scz]),
    '');
check('slug desconocido + TIENDA -> cae a SCZ',
    call_private('resolve_sucursal_slug', ['sucursal-borrada', true, $map, $scz]),
    'sucursal-scz-stock');

echo "\n=== 3. Producto VARIABLE ===\n";
$vars = [
    ['id'=>11,'stock'=>3,'stock_status'=>'instock','price'=>250.0,'sku'=>'A-1','sucursal'=>'sucursal-cbba-stock','color'=>'negro'],
    ['id'=>12,'stock'=>0,'stock_status'=>'outofstock','price'=>250.0,'sku'=>'A-2','sucursal'=>'sucursal-lpz-stock','color'=>'negro'],
    ['id'=>13,'stock'=>5,'stock_status'=>'instock','price'=>310.0,'sku'=>'A-3','sucursal'=>'sucursal-scz-stock','color'=>'azul-marino'],
];
$row = build_variable($vars, false);
check('descarta variaciones en 0', count($row['lines']), 2);
check('stock total suma solo las positivas', $row['stock_total'], 8);
check('precio minimo', $row['price_min'], 250.0);
check('precio maximo', $row['price_max'], 310.0);
check('lineas ordenadas por sucursal', $row['lines'][0]['sucursal_name'], 'COCHABAMBA');
check('color resuelto a nombre', $row['lines'][1]['color'], 'Azul Marino');

$row = build_variable($vars, false, true);
check('include_empty_lines incluye la de 0', count($row['lines']), 3);

// Variaciones sin pa_sucursal cuyo padre esta en TIENDA.
$vars_sin_suc = [
    ['id'=>21,'stock'=>7,'stock_status'=>'instock','price'=>99.0,'sku'=>'B-1','sucursal'=>'','color'=>'negro'],
];
$row = build_variable($vars_sin_suc, true);
check('variacion sin sucursal + TIENDA -> badge SCZ', $row['lines'][0]['label'], 'SCZ');
$row = build_variable($vars_sin_suc, false);
check('variacion sin sucursal fuera de TIENDA -> S/S', $row['lines'][0]['label'], 'S/S');

echo "\n=== 4. Producto SIMPLE (el bug que arreglamos) ===\n";
$row = build_simple(['_stock'=>'4','_stock_status'=>'instock','_price'=>'120.50'], true);
check('simple en TIENDA genera linea', count($row['lines']), 1);
check('simple en TIENDA -> badge SCZ', $row['lines'][0]['label'], 'SCZ');
check('simple reporta su cantidad', $row['lines'][0]['qty'], 4);
check('simple stock_total', $row['stock_total'], 4);
check('simple precio', $row['price_min'], 120.50);
check('simple sin color', $row['lines'][0]['color'], '');

$row = build_simple(['_stock'=>'4','_stock_status'=>'instock','_price'=>'120.50'], false);
check('simple fuera de TIENDA sigue mostrando stock', count($row['lines']), 1);
check('simple fuera de TIENDA -> sin sucursal', $row['lines'][0]['sucursal_slug'], '');

// Simple sin gestion de stock: _stock vacio pero en stock.
$row = build_simple(['_stock'=>'','_stock_status'=>'instock','_price'=>'80'], true);
check('simple sin gestion de stock aun aparece', count($row['lines']), 1);
check('simple sin gestion: qty null', $row['lines'][0]['qty'], null);
check('simple sin gestion: status instock', $row['lines'][0]['stock_status'], 'instock');

// Simple agotado y sin gestion -> no debe generar linea.
$row = build_simple(['_stock'=>'0','_stock_status'=>'outofstock','_price'=>'80'], true);
check('simple agotado no genera linea', count($row['lines']), 0);

// ── titulo(): entidades de wptexturize ───────────────────────────────────
// `get_the_title()` devuelve la ENTIDAD literal "&#8211;", no el carácter. Si
// no se decodifica, `esc_html()` y `esc_textarea()` la vuelven a escapar y el
// vendedor termina copiando "SMARTWATCH B22 &#8211; ANDROID 8.1".
echo "\n[ titulo() ]\n";

check(
    'guion largo de wptexturize -> caracter real',
    VCV_Query::titulo('texturizado'),
    'SMARTWATCH B22 – ANDROID 8.1 – 4G(4+64GB)'
);
check(
    'ampersand escapado -> caracter real',
    VCV_Query::titulo('ampersand'),
    'Tom & Jerry'
);
check(
    'titulo sin entidades queda igual',
    VCV_Query::titulo('limpio'),
    'RELOJ ANALOGICO DOM 1345'
);

echo "\n────────────────────────────────\n";
echo "PASS: $pass   FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);

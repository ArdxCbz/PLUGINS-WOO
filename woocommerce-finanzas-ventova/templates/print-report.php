<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Vista de impresión autónoma del Estado de Resultados (tamaño carta).
 * La sirve FIN_Admin::handle_print_report() en una pestaña nueva. Reutiliza el
 * partial templates/income-statement.php para que coincida con la pestaña.
 *
 * @var array  $data       Resultado de FIN_Reports::income_statement()
 * @var string $from        'Y-m-d'
 * @var string $to          'Y-m-d'
 * @var string $site_name   Nombre del negocio (get_bloginfo)
 * @var string $logo_url    URL del logo (puede venir vacío)
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estado de resultados — <?php echo esc_html($site_name); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url(FIN_PLUGIN_URL . 'assets/css/print-report.css?v=' . FIN_VERSION); ?>">
</head>
<body>
    <div class="fin-doc">
        <header class="fin-doc__head">
            <?php if ($logo_url): ?>
                <img class="fin-doc__logo" src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($site_name); ?>">
            <?php else: ?>
                <div class="fin-doc__brand"><?php echo esc_html($site_name); ?></div>
            <?php endif; ?>
            <h1 class="fin-doc__title">Estado de resultados</h1>
        </header>

        <?php include FIN_PLUGIN_DIR . 'templates/income-statement.php'; ?>

        <div class="fin-doc__actions">
            <button type="button" onclick="window.print()">Imprimir</button>
            <button type="button" onclick="window.close()">Cerrar</button>
        </div>
    </div>
</body>
</html>

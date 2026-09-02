# Pruebas

Bancos de pruebas sin WordPress: stubs de las funciones de WP + Reflection sobre los
métodos privados. No requieren PHPUnit ni una instalación de WordPress.

```bash
cd complementos/ventova-catalogo-vendedor
php tests/test-vcv-query.php      # motor de datos y regla de sucursal
php tests/test-vcv-catalogo.php   # filtros, precio y render de stock
php tests/test-vcv-resumen.php    # generador HTML→WhatsApp y tokens
```

Cada archivo sale con código 0 si todo pasa y 1 si algo falla.

`test-vcv-resumen.php` además corre el generador contra las descripciones **reales** de
`descripcion-html/` y comprueba que el resultado no tenga etiquetas ni estilos, y que
conserve viñetas y negritas. Imprime una muestra al final para revisar la calidad a ojo.

> ⚠️ Estas pruebas cubren lógica PHP. **El SQL de `VCV_Query` no se ejecuta aquí** —
> hace falta una base real (Fase 5).

> ⚠️ Esta carpeta es de desarrollo: **excluirla del .zip** al empaquetar el plugin.

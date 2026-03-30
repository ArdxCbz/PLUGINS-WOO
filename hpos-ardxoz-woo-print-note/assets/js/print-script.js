// Auto-imprimir cuando la página esté completamente cargada
window.addEventListener('load', function () {
    // Esperar un momento para que las fuentes se carguen
    setTimeout(function () {
        window.print();
    }, 500);
});

// Cerrar la ventana después de imprimir o cancelar (opcional)
/*
window.addEventListener('afterprint', function() {
    window.close();
});
*/

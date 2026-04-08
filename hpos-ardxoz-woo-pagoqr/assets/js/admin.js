jQuery(document).ready(function($) {
    'use strict';

    $(document).on('click', '.hpos-ardxoz-pagoqr-upload-wrapper .upload-file', function(e) {
        e.preventDefault();
        
        var $wrapper = $(this).closest('.hpos-ardxoz-pagoqr-upload-wrapper');
        var $targetInput = $('#' + $wrapper.data('target'));
        var $previewArea = $wrapper.find('.preview-area');

        var uploader = wp.media({
            title: 'Seleccionar Imagen',
            button: { text: 'Usar esta imagen' },
            multiple: false
        }).on('select', function() {
            var attachment = uploader.state().get('selection').first().toJSON();
            $targetInput.val(attachment.url);
            $previewArea.html('<img src="' + attachment.url + '" style="max-width:200px; display:block; border:1px solid #ccc; padding:5px;"><button type="button" class="button remove-file" style="margin-top:5px;">Eliminar</button>');
        }).open();
    });

    $(document).on('click', '.hpos-ardxoz-pagoqr-upload-wrapper .remove-file', function(e) {
        e.preventDefault();
        var $wrapper = $(this).closest('.hpos-ardxoz-pagoqr-upload-wrapper');
        var $targetInput = $('#' + $wrapper.data('target'));
        var $previewArea = $wrapper.find('.preview-area');

        $targetInput.val('');
        $previewArea.empty();
    });
});

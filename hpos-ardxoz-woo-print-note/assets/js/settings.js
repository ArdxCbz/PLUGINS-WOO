jQuery(document).ready(function ($) {
    var frame;

    $('#ventova_upload_logo').on('click', function (e) {
        e.preventDefault();

        if (frame) {
            frame.open();
            return;
        }

        frame = wp.media({
            title: 'Seleccionar Logo',
            button: { text: 'Usar este logo' },
            multiple: false,
            library: { type: 'image' }
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#ventova_logo_id').val(attachment.id);
            $('#ventova_logo_preview').attr('src', attachment.url).show();
            $('#ventova_remove_logo').show();
        });

        frame.open();
    });

    $('#ventova_remove_logo').on('click', function (e) {
        e.preventDefault();
        $('#ventova_logo_id').val('');
        $('#ventova_logo_preview').hide();
        $(this).hide();
    });
});

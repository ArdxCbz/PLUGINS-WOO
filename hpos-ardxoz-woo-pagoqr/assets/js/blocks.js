/**
 * Integración de Pago QR HPOS con WooCommerce Blocks Checkout
 */
( function() {
    'use strict';

    var registerPaymentMethod = wc.blocksRegistry.registerPaymentMethod;
    var createElement         = wp.element.createElement;
    var decodeEntities        = wp.htmlEntities.decodeEntities;
    var settings              = wc.wcSettings.getSetting( 'hpos_ardxoz_pagoqr_data', {} );

    var title       = decodeEntities( settings.title || 'Pago por QR' );
    var description = decodeEntities( settings.description || '' );
    var icon        = settings.icon || '';

    var Label = function( props ) {
        var children = [ title ];
        if ( icon ) {
            children.unshift(
                createElement( 'img', {
                    src: icon,
                    alt: title,
                    style: { maxWidth: '30px', marginRight: '8px', verticalAlign: 'middle' }
                })
            );
        }
        return createElement( 'span', null, children );
    };

    var Content = function( props ) {
        return createElement( 'div', null,
            description ? createElement( 'p', null, description ) : null
        );
    };

    registerPaymentMethod({
        name: 'hpos_ardxoz_pagoqr',
        label: createElement( Label, null ),
        content: createElement( Content, null ),
        edit: createElement( Content, null ),
        canMakePayment: function() { return true; },
        ariaLabel: title,
        supports: {
            features: settings.supports || [ 'products' ]
        }
    });
})();

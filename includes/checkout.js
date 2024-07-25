const methodData = window.wc.wcSettings.getSetting( 'paymentMethodData', {} );
const settings = methodData.bfx_payment || {};
const label = window.wp.htmlEntities.decodeEntities( settings.title ) || window.wp.i18n.__( 'Bitfinex Pay Gateway', 'bfx_payment' );

const Content = () => {
    return window.wp.htmlEntities.decodeEntities( settings.description || '' );
};

const Block_Gateway = {
    name: 'bfx_payment',
    label: label,
    content: Object( window.wp.element.createElement )( Content, null ),
    edit: Object( window.wp.element.createElement )( Content, null ),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
      features: settings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod( Block_Gateway );

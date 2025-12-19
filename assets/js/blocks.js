const maib_mia_settings = window.wc.wcSettings.getSetting('maib_mia_data', {});
const maib_mia_title = window.wp.htmlEntities.decodeEntities(maib_mia_settings.title);

const maib_mia_content = () => {
    return window.wp.htmlEntities.decodeEntities(maib_mia_settings.description || '');
};

const maib_mia_label = () => {
    let icon = maib_mia_settings.icon
        ? window.wp.element.createElement(
            'img',
            {
                alt: maib_mia_title,
                title: maib_mia_title,
                src: maib_mia_settings.icon,
                style: { float: 'right', paddingRight: '1em' }
            }
        )
        : null;

    let label = window.wp.element.createElement(
        'span',
        icon ? { style: { width: '100%' } } : null,
        maib_mia_title,
        icon
    );

    return label;
};

const maib_mia_blockGateway = {
    name: maib_mia_settings.id,
    label: Object(window.wp.element.createElement)(maib_mia_label, null),
    icons: [{id: 'mia', alt: maib_mia_settings.title, src: maib_mia_settings.icon}],
    content: Object(window.wp.element.createElement)(maib_mia_content, null),
    edit: Object(window.wp.element.createElement)(maib_mia_content, null),
    canMakePayment: () => true,
    ariaLabel: maib_mia_title,
    supports: {
        features: maib_mia_settings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(maib_mia_blockGateway);

const colors = require('tailwindcss/colors');

const themedPalettes = ['zinc', 'gray', 'emerald', 'green', 'red', 'amber', 'blue', 'sky', 'purple'];
const themeColors = Object.fromEntries(themedPalettes.map(name => [
    name,
    Object.fromEntries(Object.entries(colors[name]).map(([shade, hex]) => {
        const channels = hex.slice(1).match(/../g).map(channel => parseInt(channel, 16));
        return [shade, `rgb(var(--color-${name}-${shade}, ${channels.join(' ')}) / <alpha-value>)`];
    })),
]));

module.exports = {
    darkMode: 'class',
    theme: { extend: { colors: themeColors } },
    content: {
        relative: true,
        files: [
            '../public_html/app/{controllers,core,models,views}/**/*.php',
            '../public_html/assets/js/*.js',
        ],
    },
};

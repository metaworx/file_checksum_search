const path = require('path');

module.exports = {
    entry: './src/sidebar.js',
    output: {
        path: path.resolve(__dirname, 'js'),
        filename: 'sidebar.js',
        library: { type: 'var', name: 'FileChecksumSearchSidebar' },
    },
    module: {
        rules: [
            { test: /\.svg$/, type: 'asset/source' },
        ],
    },
    mode: 'production',
};

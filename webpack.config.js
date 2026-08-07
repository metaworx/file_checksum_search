const path = require('path');

module.exports = {
	entry: {
		sidebar: './src/sidebar.ts',
		duplicates: './src/duplicates.ts',
		'settings-admin': './src/settings-admin.ts',
	},
	output: {
		path: path.resolve(__dirname, 'js'),
		filename: '[name].js',
		library: { type: 'var', name: 'FileChecksumSearchSidebar' },
	},
	resolve: {
		extensions: ['.ts', '.js'],
	},
	module: {
		rules: [
			{ test: /\.ts$/, use: 'ts-loader', exclude: /node_modules/ },
			{ test: /\.svg$/, type: 'asset/source' },
		],
	},
	mode: 'production',
};

const path = require( 'path' );

module.exports = {
	entry: {
		sidebar: './src/sidebar.js',
		duplicates: './src/duplicates.js',
	},
	output: {
		path: path.resolve( __dirname, 'js' ),
		filename: '[name].js',
		library: { type: 'var', name: 'FileChecksumSearchSidebar' },
	},
	module: {
		rules: [
			{ test: /\.svg$/, type: 'asset/source' },
		],
	},
	mode: 'production',
};

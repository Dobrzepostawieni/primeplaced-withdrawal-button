const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

const apps = {
	'ppwb-form': { path: 'front/form', src: 'src', ext: 'ts' },
};

const entry = {};
Object.entries( apps ).forEach( ( [ outputName, config ] ) => {
	entry[ outputName ] = path.resolve(
		process.cwd(),
		`assets/${ config.src }/${ config.path }.${ config.ext ?? 'tsx' }`
	);
} );

/**
 * Switches sass-loader to the modern API and lets SCSS resolve
 * `@use "styles/..."` and `@use "shared/..."` from assets/src.
 *
 * @param {Array} rules Webpack module rules.
 * @return {Array} Updated rules.
 */
function updateRules( rules ) {
	return rules.map( ( rule ) => {
		if ( Array.isArray( rule.use ) ) {
			rule.use = rule.use.map( ( loader ) => {
				const name = typeof loader === 'string' ? loader : loader.loader;
				if ( ! name || ! name.includes( 'sass-loader' ) ) {
					return loader;
				}
				const options = typeof loader === 'object' && loader.options ? loader.options : {};
				return {
					loader: require.resolve( 'sass-loader' ),
					options: {
						...options,
						sourceMap: true,
						api: 'modern',
						sassOptions: {
							loadPaths: [
								path.resolve( process.cwd(), 'node_modules' ),
								path.resolve( process.cwd(), 'assets/src' ),
							],
							quietDeps: true,
							silenceDeprecations: [ 'legacy-js-api' ],
						},
					},
				};
			} );
		}

		if ( rule.oneOf ) {
			rule.oneOf = updateRules( rule.oneOf );
		}

		return rule;
	} );
}

// Drop all comments from the minified output.
const minimizers = ( defaultConfig.optimization.minimizer || [] ).map( ( plugin ) => {
	if ( plugin.constructor.name === 'TerserPlugin' ) {
		plugin.options.extractComments = false;
		plugin.options.terserOptions = {
			...plugin.options.terserOptions,
			format: {
				...( plugin.options.terserOptions?.format || {} ),
				comments: false,
			},
		};
	}
	return plugin;
} );

module.exports = {
	...defaultConfig,
	entry,
	output: {
		path: path.join( __dirname, 'assets/build' ),
		filename: '[name].js',
		chunkFilename: '[name].[contenthash].js',
	},
	resolve: {
		...defaultConfig.resolve,
		extensions: [ '.ts', '.tsx', '.js', '.jsx', ...defaultConfig.resolve.extensions ],
	},
	module: {
		...defaultConfig.module,
		rules: updateRules( defaultConfig.module.rules ),
	},
	optimization: {
		...defaultConfig.optimization,
		minimizer: minimizers,
	},
};

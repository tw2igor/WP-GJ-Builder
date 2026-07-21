/**
 * Кастомизация дефолтного webpack-конфига @wordpress/scripts: наши
 * источники лежат в assets/{editor,admin}/ (не в стандартном src/),
 * скомпилированный вывод — в assets/build/ (раздел "Репозиторий и
 * файловая структура" плана). Каждый вход генерирует свой {handle}.asset.php
 * с точным списком wp-* зависимостей и версией по хэшу контента.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		editor: path.resolve( __dirname, 'assets/editor/index.js' ),
		admin: path.resolve( __dirname, 'assets/admin/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/build' ),
		filename: '[name].js',
	},
};

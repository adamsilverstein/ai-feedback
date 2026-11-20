const wpScriptsConfig = require('@wordpress/scripts/config/eslint.config.cjs');

module.exports = [
	...wpScriptsConfig,
	// The Gutenberg build the Suggestion Mode e2e suite runs against.
	{ ignores: ['.cache/**'] },
	{
		rules: {
			'jsdoc/no-undefined-types': [
				'error',
				{ definedTypes: ['JSX', 'Element'] },
			],
		},
	},
	{
		files: ['**/__tests__/**/*.js', '**/*.test.js', 'tests/unit/**/*.js'],
		rules: {
			'import/no-extraneous-dependencies': 'off',
		},
	},
	{
		files: ['tests/e2e/**/*.js'],
		rules: {
			'import/no-extraneous-dependencies': 'off',
			// Playwright fixtures call `use()`, which is not a React hook.
			'react-hooks/rules-of-hooks': 'off',
		},
	},
];

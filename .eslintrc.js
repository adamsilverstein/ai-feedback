module.exports = {
	extends: ['plugin:@wordpress/eslint-plugin/recommended'],
	root: true,
	rules: {
		'jsdoc/no-undefined-types': [
			'error',
			{ definedTypes: ['JSX', 'Element'] },
		],
	},
	overrides: [
		{
			files: ['src/**/test/*.js'],
			extends: ['plugin:@wordpress/eslint-plugin/test-unit'],
		},
		{
			files: ['tests/e2e/**/*.js'],
			rules: {
				'import/no-extraneous-dependencies': 'off',
			},
		},
	],
};

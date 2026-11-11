import { transformWithOxc } from 'vite';
import { defineConfig } from 'vitest/config';

/**
 * Compile JSX in the plugin's .js files, which Vite only parses as plain JS.
 */
const jsxInJs = {
	name: 'ai-feedback-jsx-in-js',
	enforce: 'pre',
	transform(code, id) {
		if (!/\/src\/.*\.js$/.test(id)) {
			return null;
		}
		return transformWithOxc(code, id, {
			lang: 'jsx',
			jsx: { runtime: 'automatic' },
		});
	},
};

export default defineConfig({
	plugins: [jsxInJs],
	test: {
		include: ['src/**/*.test.js'],
		environment: 'jsdom',
		setupFiles: ['./tests/js/vitest.setup.js'],
	},
});

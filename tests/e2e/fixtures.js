/**
 * Custom test fixtures for AI Feedback E2E tests.
 */
const { test: base, expect } = require( '@playwright/test' );
const { Admin, Editor, PageUtils, RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * AI Feedback custom utilities.
 */
class AIFeedbackUtils {
	constructor( { page, admin } ) {
		this.page = page;
		this.admin = admin;
	}

	/**
	 * Open the AI Feedback sidebar.
	 */
	async openSidebar() {
		// Click the "AI Feedback" menu item in the editor's more menu
		const sidebarButton = this.page.getByRole( 'button', { name: 'AI Feedback' } );
		
		// Check if sidebar is already open by looking for the panel
		const isOpen = await this.page.getByRole( 'region', { name: 'AI Feedback' } ).isVisible().catch( () => false );
		
		if ( ! isOpen ) {
			// Open the options menu if not already open
			const optionsButton = this.page.getByRole( 'button', { name: 'Options' } );
			const menuVisible = await this.page.getByRole( 'menu', { name: 'Options' } ).isVisible().catch( () => false );
			
			if ( ! menuVisible ) {
				await optionsButton.click();
			}
			
			// Click AI Feedback menu item
			await sidebarButton.click();
		}
		
		// Wait for the sidebar to be visible
		await this.page.getByRole( 'region', { name: 'AI Feedback' } ).waitFor( { state: 'visible' } );
	}

	/**
	 * Select a model from the model selector.
	 *
	 * @param {string} modelId - Model ID to select (e.g., 'gpt-4o').
	 */
	async selectModel( modelId ) {
		const modelSelect = this.page.getByLabel( 'AI Model' );
		await modelSelect.selectOption( modelId );
	}

	/**
	 * Toggle a focus area checkbox.
	 *
	 * @param {string} label - Label of the focus area (e.g., 'Content Quality').
	 * @param {boolean} checked - Whether to check or uncheck.
	 */
	async toggleFocusArea( label, checked ) {
		const checkbox = this.page.getByLabel( label, { exact: true } );
		if ( checked ) {
			await checkbox.check();
		} else {
			await checkbox.uncheck();
		}
	}

	/**
	 * Select a target tone.
	 *
	 * @param {string} toneId - Tone ID to select (e.g., 'academic').
	 */
	async selectTone( toneId ) {
		const toneSelect = this.page.getByLabel( 'Target Tone' );
		await toneSelect.selectOption( toneId );
	}

	/**
	 * Expand Review Settings panel if collapsed.
	 */
	async expandReviewSettings() {
		const reviewSettingsButton = this.page.getByRole( 'button', { name: 'Review Settings' } );
		const isExpanded = await reviewSettingsButton.getAttribute( 'aria-expanded' );
		
		if ( isExpanded === 'false' ) {
			await reviewSettingsButton.click();
		}
	}

	/**
	 * Wait for settings to be saved (accounting for debounce).
	 *
	 * @param {number} ms - Milliseconds to wait (default 600ms for 500ms debounce + buffer).
	 */
	async waitForSettingsSave( ms = 600 ) {
		await this.page.waitForTimeout( ms );
	}
}

/**
 * Extend the base test with custom fixtures.
 */
const test = base.extend( {
	admin: async ( { page, pageUtils }, use ) => {
		await use( new Admin( { page, pageUtils } ) );
	},
	editor: async ( { page }, use ) => {
		await use( new Editor( { page } ) );
	},
	pageUtils: async ( { page }, use ) => {
		await use( new PageUtils( { page } ) );
	},
	requestUtils: async ( {}, use ) => {
		const requestUtils = await RequestUtils.setup( {
			baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
			user: {
				username: 'admin',
				password: 'password',
			},
		} );
		await use( requestUtils );
	},
	aiFeedback: async ( { page, admin }, use ) => {
		await use( new AIFeedbackUtils( { page, admin } ) );
	},
} );

module.exports = { test, expect };

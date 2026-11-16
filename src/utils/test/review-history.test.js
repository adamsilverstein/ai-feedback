/**
 * Internal dependencies
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { getSettings, setSettings } from '@wordpress/date';
import {
	describeChange,
	formatNoteCount,
	formatReviewDate,
	getReviewISODate,
	getReviewTime,
} from '../review-history';

const originalSettings = getSettings();
const originalTZ = process.env.TZ;

/**
 * Set the site timezone and the browser's.
 *
 * @param {Object} timezone Site timezone settings.
 * @param {string} browser  Browser timezone.
 */
function setTimezones(timezone, browser) {
	process.env.TZ = browser;
	setSettings({
		...originalSettings,
		formats: { ...originalSettings.formats, datetime: 'Y-m-d H:i' },
		timezone: { ...originalSettings.timezone, string: '', ...timezone },
	});
}

const SITE_UTC = { offset: 0, offsetFormatted: '', string: '' };
const SITE_NEW_YORK = { offset: -5, string: 'America/New_York' };

describe('formatReviewDate', () => {
	afterEach(() => {
		process.env.TZ = originalTZ;
		setSettings(originalSettings);
	});

	beforeEach(() => {
		setTimezones(SITE_UTC, 'America/Los_Angeles');
	});

	it('returns nothing without a timestamp', () => {
		expect(formatReviewDate('')).toBe('');
	});

	it('returns nothing for an unparseable timestamp', () => {
		expect(formatReviewDate('garbage')).toBe('');
	});

	it('shows a UTC timestamp in the site timezone, not the browser one', () => {
		expect(formatReviewDate('2026-11-14T04:46:00+00:00')).toBe(
			'2026-11-14 04:46'
		);
	});

	it('treats a legacy timestamp without an offset as site time', () => {
		expect(formatReviewDate('2026-11-14 04:46:00')).toBe(
			'2026-11-14 04:46'
		);
	});

	it('converts to a named site timezone', () => {
		setTimezones(SITE_NEW_YORK, 'Asia/Tokyo');
		expect(formatReviewDate('2026-11-14T04:46:00+00:00')).toBe(
			'2026-11-13 23:46'
		);
		expect(formatReviewDate('2026-11-13 23:46:00')).toBe(
			'2026-11-13 23:46'
		);
	});
});

describe('getReviewISODate', () => {
	afterEach(() => {
		process.env.TZ = originalTZ;
		setSettings(originalSettings);
	});

	it('returns a UTC timestamp as ISO 8601', () => {
		setTimezones(SITE_NEW_YORK, 'Asia/Tokyo');
		expect(getReviewISODate('2026-11-14T04:46:00+00:00')).toBe(
			'2026-11-14T04:46:00.000Z'
		);
	});

	it('reads a legacy timestamp as site time', () => {
		setTimezones(SITE_NEW_YORK, 'Asia/Tokyo');
		expect(getReviewISODate('2026-11-13 23:46:00')).toBe(
			'2026-11-14T04:46:00.000Z'
		);
	});

	it('returns nothing for a missing or unparseable timestamp', () => {
		expect(getReviewISODate('')).toBe('');
		expect(getReviewISODate('garbage')).toBe('');
	});
});

describe('getReviewTime', () => {
	afterEach(() => {
		process.env.TZ = originalTZ;
		setSettings(originalSettings);
	});

	it('compares legacy and UTC timestamps as the same instant', () => {
		setTimezones(SITE_NEW_YORK, 'America/Los_Angeles');
		expect(getReviewTime('2026-11-13 23:46:00')).toBe(
			getReviewTime('2026-11-14T04:46:00+00:00')
		);
	});

	it('returns 0 without a timestamp', () => {
		expect(getReviewTime('')).toBe(0);
	});
});

describe('describeChange', () => {
	it('returns nothing for the oldest review', () => {
		expect(describeChange(3, undefined)).toBe('');
	});

	it('describes no change', () => {
		expect(describeChange(3, 3)).toBe('Same as previous review');
	});

	it('describes fewer items', () => {
		expect(describeChange(1, 3)).toBe('2 fewer items than previous review');
		expect(describeChange(2, 3)).toBe('1 fewer item than previous review');
	});

	it('describes more items', () => {
		expect(describeChange(5, 3)).toBe('2 more items than previous review');
	});
});

describe('formatNoteCount', () => {
	it('pluralizes', () => {
		expect(formatNoteCount(1)).toBe('1 feedback item');
		expect(formatNoteCount(4)).toBe('4 feedback items');
	});
});

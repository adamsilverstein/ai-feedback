/**
 * Internal dependencies
 */
import { describe, it, expect } from 'vitest';
import { isPostSaved } from '../post-status';

describe('isPostSaved', () => {
	it.each([
		[undefined, undefined, false],
		[0, 'draft', false],
		[12, 'auto-draft', false],
		[12, 'draft', true],
		[12, 'publish', true],
	])('isPostSaved( %p, %p ) is %p', (postId, postStatus, expected) => {
		expect(isPostSaved(postId, postStatus)).toBe(expected);
	});
});

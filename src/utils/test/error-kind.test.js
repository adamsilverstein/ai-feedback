/**
 * Internal dependencies
 */
import { getErrorKind } from '../error-kind';

describe('getErrorKind', () => {
	it.each([
		[{ code: 'prompt_client_error', data: { status: 401 } }, 'credentials'],
		[{ code: 'prompt_client_error', data: { status: 402 } }, 'credentials'],
		[
			{
				code: 'ai_request_failed',
				message: 'Your credit balance is too low',
			},
			'credentials',
		],
		[
			{
				code: 'prompt_client_error',
				message: 'You exceeded your current quota',
				data: { status: 429 },
			},
			'credentials',
		],
		[
			{
				code: 'prompt_client_error',
				message: 'Slow down',
				data: { status: 429 },
			},
			'rate_limit',
		],
		[
			{ code: 'rate_limit_exceeded', message: 'Too many reviews' },
			'rate_limit',
		],
		[
			{
				code: 'prompt_network_error',
				message: 'Timed out',
				data: { status: 503 },
			},
			null,
		],
		[
			{
				code: 'ai_service_unavailable',
				message: 'The AI service is temporarily unavailable.',
				data: { status: 503, retry_after: 30 },
			},
			'unavailable',
		],
		[{}, null],
	])('classifies %j as %s', (error, kind) => {
		expect(getErrorKind(error)).toBe(kind);
	});
});

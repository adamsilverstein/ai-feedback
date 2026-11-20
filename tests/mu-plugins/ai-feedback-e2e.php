<?php
/**
 * Plugin Name: AI Feedback E2E Helpers
 * Description: Lets Playwright run reviews without a real AI provider. Only loaded by wp-env.
 *
 * Requests that carry the `ai_feedback_e2e` cookie get the plugin's built-in
 * mock AI responses, skip the per-user review rate limit, and never spawn
 * WP-Cron. Tests run cron themselves by requesting wp-cron.php with the
 * cookie, so queued AI replies also use the mock. Requests without
 * the cookie, such as manual testing in the same environment, and any site
 * that is not a local environment are untouched.
 *
 * @package AI_Feedback
 */

declare( strict_types=1 );

if ( empty( $_COOKIE['ai_feedback_e2e'] ) || 'local' !== wp_get_environment_type() ) {
	return;
}

// Lets a test treat pending suggestions as old enough to clean up.
if ( isset( $_COOKIE['ai_feedback_e2e_cleanup_delay'] ) && is_string( $_COOKIE['ai_feedback_e2e_cleanup_delay'] ) ) {
	$ai_feedback_e2e_cleanup_delay = absint( $_COOKIE['ai_feedback_e2e_cleanup_delay'] );
	add_filter(
		'ai_feedback_suggestion_cleanup_delay',
		static fn (): int => $ai_feedback_e2e_cleanup_delay
	);
	unset( $ai_feedback_e2e_cleanup_delay );
}

if ( ! defined( 'AI_FEEDBACK_MOCK_MODE' ) ) {
	define( 'AI_FEEDBACK_MOCK_MODE', true );
}

// A spawned loopback carries no cookie, so it would send queued replies to a
// real provider.
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}

// Clear a lock left by a cookie-less spawn so the test's own run is not skipped.
if ( wp_doing_cron() ) {
	delete_transient( 'doing_cron' );
}

add_filter(
	'rest_pre_dispatch',
	static function ( mixed $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
		$user_id = get_current_user_id();
		// Answering a mention shares the hourly review limit.
		if ( $user_id && ( '/ai-feedback/v1/review' === $request->get_route() || preg_match( '#^/ai-feedback/v1/replies/\d+/process$#', $request->get_route() ) ) ) {
			delete_user_meta( $user_id, 'ai_feedback_reviews' );
		}
		return $result;
	},
	10,
	3
);

// Queue mentions for right away, so the cron run after each test drains
// them in mock mode even when a test fails before the editor answers.
add_filter( 'ai_feedback_mention_reply_delay', '__return_zero' );

// Let tests set a UTC offset site timezone through the settings endpoint.
add_action(
	'rest_api_init',
	static function (): void {
		register_setting(
			'general',
			'gmt_offset',
			array(
				'type'         => 'number',
				'show_in_rest' => true,
			)
		);
	}
);

// Mock replies return an edit when asked to make the change, so tests can
// cover the reply to edit flow, and revise a pending edit when asked for a
// shorter or different one, which they only do when the prompt carries the
// pending edit. Everything else gets the prose mock reply.
add_filter(
	'ai_feedback_mock_reply',
	static function ( string $reply, string $prompt ): string {
		$revisions = array(
			// Quick reply text => [ pending replacement it revises, new replacement ].
			'Make it shorter.'          => array( 'in 2025', "in '25" ),
			'Try a different version.' => array( "in '25", 'in fiscal 2025' ),
		);
		if (
			preg_match( '/NEW USER REPLY:\n(.*?)\n\n/s', $prompt, $asked )
			&& isset( $revisions[ trim( $asked[1] ) ] )
		) {
			list( $pending, $revised ) = $revisions[ trim( $asked[1] ) ];
			if ( ! str_contains( $prompt, "YOUR PENDING SUGGESTED EDIT (not applied, so the block content above does not include it):\nreplace \"last year\" with \"{$pending}\"" ) ) {
				return $reply;
			}

			return (string) wp_json_encode(
				array(
					'reply' => "Revised: \"last year\" now reads \"{$revised}\".",
					'edit'  => array(
						'type'    => 'replace_text',
						'find'    => 'last year',
						'replace' => $revised,
					),
				)
			);
		}

		if (
			! preg_match( '/NEW USER REPLY:\n(.*?)\n\n/s', $prompt, $user_reply )
			|| ! preg_match( '/make (?:this|that) change/i', $user_reply[1] )
			|| ! preg_match( '/^Block content: (.*?)\n\n/ms', $prompt, $block )
			|| ! str_contains( $block[1], 'last year' )
		) {
			return $reply;
		}

		// Asked to change both lines of a block split by a line break, the
		// edit's find runs across the break, copying it as a newline.
		if ( str_contains( $user_reply[1], 'both lines' ) ) {
			return (string) wp_json_encode(
				array(
					'reply' => 'Done: both lines now read "in 2025".',
					'edit'  => array(
						'type'    => 'replace_text',
						'find'    => "40%\nlast year",
						'replace' => "40%\nin 2025",
					),
				)
			);
		}

		return (string) wp_json_encode(
			array(
				'reply' => 'Done: "last year" now reads "in 2025".',
				'edit'  => array(
					'type'    => 'replace_text',
					'find'    => 'last year',
					'replace' => 'in 2025',
				),
			)
		);
	},
	10,
	2
);

// A review of a block starting "Backslash fixture" returns feedback holding
// backslashes, which must reach the saved note unchanged.
add_filter(
	'ai_feedback_mock_review',
	static function ( array $response, array $blocks ): array {
		foreach ( $blocks as $block ) {
			$content = is_array( $block ) && is_string( $block['content'] ?? null ) ? $block['content'] : '';
			if ( str_starts_with( $content, 'Backslash fixture' ) ) {
				$response['feedback'] = array(
					array(
						'block_id' => $block['clientId'] ?? '',
						'category' => 'content',
						'severity' => 'suggestion',
						'title'    => 'Paths',
						'feedback' => 'Use C:\\path\\new and match \\d+\\.\\d+ in {"say":"\\"hi\\""}.',
					),
				);
				break;
			}
		}

		return $response;
	},
	10,
	2
);

// A review of a post holding the structural fixture text proposes one of
// each structural edit: move "The real opening" above "Background", remove
// "This repeats section two" and add a heading above "Next steps".
add_filter(
	'ai_feedback_mock_review',
	static function ( array $response, array $blocks ): array {
		$ids = array();
		foreach ( $blocks as $block ) {
			$content = is_array( $block ) && is_string( $block['content'] ?? null ) ? $block['content'] : '';
			foreach ( array( 'Background', 'The real opening', 'This repeats section two', 'Next steps' ) as $start ) {
				if ( str_starts_with( $content, $start ) ) {
					$ids[ $start ] = $block['clientId'] ?? '';
				}
			}
		}
		if ( 4 !== count( $ids ) ) {
			return $response;
		}

		$item = static fn ( string $block_id, string $title, array $action ): array => array(
			'block_id'     => $block_id,
			'category'     => 'flow',
			'severity'     => 'important',
			'title'        => $title,
			'feedback'     => 'The structure could be clearer.',
			'suggestion'   => 'Restructure this part.',
			'block_action' => $action,
		);

		$response['feedback'] = array(
			$item(
				$ids['The real opening'],
				'Lead with the real opening',
				array(
					'type'            => 'move',
					'target_block_id' => $ids['Background'],
					'position'        => 'before',
				)
			),
			$item( $ids['This repeats section two'], 'Cut the repetition', array( 'type' => 'remove' ) ),
			$item(
				$ids['Next steps'],
				'Add a heading',
				array(
					'type'     => 'insert',
					'position' => 'before',
					'block'    => array(
						'name'    => 'core/heading',
						'level'   => 3,
						'content' => 'How to try it',
					),
				)
			),
		);

		return $response;
	},
	10,
	2
);

// A review of a paragraph starting "Move me below the quote" next to a
// quote cited "Ada Lovelace" moves the paragraph below the quote, a move
// whose target has inner blocks.
add_filter(
	'ai_feedback_mock_review',
	static function ( array $response, array $blocks ): array {
		$ids = array();
		foreach ( $blocks as $block ) {
			$name    = is_array( $block ) && is_string( $block['name'] ?? null ) ? $block['name'] : '';
			$content = is_array( $block ) && is_string( $block['content'] ?? null ) ? $block['content'] : '';
			if ( 'core/quote' === $name && 'Ada Lovelace' === $content ) {
				$ids['quote'] = $block['clientId'] ?? '';
			} elseif ( str_starts_with( $content, 'Move me below the quote' ) ) {
				$ids['paragraph'] = $block['clientId'] ?? '';
			}
		}
		if ( 2 !== count( $ids ) ) {
			return $response;
		}

		$response['feedback'] = array(
			array(
				'block_id'     => $ids['paragraph'],
				'category'     => 'flow',
				'severity'     => 'important',
				'title'        => 'Follow the quote',
				'feedback'     => 'This reads better after the quote.',
				'block_action' => array(
					'type'            => 'move',
					'target_block_id' => $ids['quote'],
					'position'        => 'after',
				),
			),
		);
		return $response;
	},
	10,
	2
);

// A reply asking to move the block moves it above the first nearby block.
add_filter(
	'ai_feedback_mock_reply',
	static function ( string $reply, string $prompt ): string {
		if (
			! preg_match( '/NEW USER REPLY:\n(.*?)\n\n/s', $prompt, $asked )
			|| ! preg_match( '/move it/i', $asked[1] )
			|| ! str_contains( $prompt, "\n- [b1] " )
		) {
			return $reply;
		}

		return (string) wp_json_encode(
			array(
				'reply' => 'Moved it to the top.',
				'edit'  => array(
					'type'            => 'move_block',
					'target_block_id' => 'b1',
					'position'        => 'before',
				),
			)
		);
	},
	20,
	2
);

// A review of a post holding the formatting fixture proposes one of each
// formatting fix: the skipped "Setup" heading level, the "You need" items
// as a list, a split after "The first idea ends here.", and alt text for
// the image. Mock reviews never show the AI the image, so the parser drops
// that alt text and the note stays a plain one.
add_filter(
	'ai_feedback_mock_review',
	static function ( array $response, array $blocks ): array {
		$ids = array();
		foreach ( $blocks as $block ) {
			$name    = is_array( $block ) && is_string( $block['name'] ?? null ) ? $block['name'] : '';
			$content = is_array( $block ) && is_string( $block['content'] ?? null ) ? $block['content'] : '';
			if ( 'core/heading' === $name && 'Setup' === $content ) {
				$ids['heading'] = $block['clientId'] ?? '';
			} elseif ( str_starts_with( $content, 'You need Node' ) ) {
				$ids['list'] = $block['clientId'] ?? '';
			} elseif ( str_starts_with( $content, 'The first idea' ) ) {
				$ids['split'] = $block['clientId'] ?? '';
			} elseif ( 'core/image' === $name ) {
				$ids['image'] = $block['clientId'] ?? '';
			}
		}
		if ( 4 !== count( $ids ) ) {
			return $response;
		}

		$item = static fn ( string $block_id, string $title, array $change ): array => array(
			'block_id'     => $block_id,
			'category'     => 'design',
			'severity'     => 'suggestion',
			'title'        => $title,
			'feedback'     => 'The formatting could be clearer.',
			'block_change' => $change,
		);

		$response['feedback'] = array(
			$item(
				$ids['heading'],
				'Fix heading level',
				array(
					'type'       => 'set_attributes',
					'attributes' => array( 'level' => 3 ),
				)
			),
			$item(
				$ids['list'],
				'Use a list',
				array(
					'type'  => 'transform',
					'to'    => 'core/list',
					'items' => array( 'Node', 'npm', 'Docker', 'Git' ),
				)
			),
			$item(
				$ids['split'],
				'Split this paragraph',
				array(
					'type'  => 'split',
					'after' => 'The first idea ends here.',
				)
			),
			$item(
				$ids['image'],
				'Add alt text',
				array(
					'type'       => 'set_attributes',
					'attributes' => array( 'alt' => 'Made up without seeing the image' ),
				)
			),
		);

		return $response;
	},
	10,
	2
);

// A review of a post holding the proofreading fixture finds seven typos
// across four paragraphs, two or three in some of them, as grammar items.
add_filter(
	'ai_feedback_mock_review',
	static function ( array $response, array $blocks ): array {
		$fixes = array(
			'Teh '       => array( array( 'Teh', 'The' ), array( 'brwon', 'brown' ), array( 'jumpd', 'jumped' ) ),
			'You '       => array( array( 'recieve', 'receive' ), array( 'alot', 'a lot' ) ),
			'A wierd'    => array( array( 'wierd', 'weird' ) ),
			'Thier plan' => array( array( 'Thier', 'Their' ) ),
		);

		$feedback = array();
		foreach ( $blocks as $block ) {
			$content = is_array( $block ) && is_string( $block['content'] ?? null ) ? $block['content'] : '';
			foreach ( $fixes as $start => $pairs ) {
				if ( ! str_starts_with( $content, $start ) ) {
					continue;
				}
				foreach ( $pairs as $pair ) {
					$feedback[] = array(
						'block_id'  => $block['clientId'] ?? '',
						'category'  => 'grammar',
						'severity'  => 'suggestion',
						'title'     => $pair[0] . ' → ' . $pair[1],
						'feedback'  => 'Spelling.',
						'quick_fix' => array(
							'find'    => $pair[0],
							'replace' => $pair[1],
						),
					);
				}
			}
		}
		if ( 7 !== count( $feedback ) ) {
			return $response;
		}

		$response['feedback'] = $feedback;
		return $response;
	},
	10,
	2
);

// A review of a paragraph split by a line break fixes a typo with a find
// that runs across the break, copied as a newline. It only does when the
// newline reaches the review intact.
add_filter(
	'ai_feedback_mock_review',
	static function ( array $response, array $blocks ): array {
		foreach ( $blocks as $block ) {
			$content = is_array( $block ) && is_string( $block['content'] ?? null ) ? $block['content'] : '';
			if ( "Revenue grew 40%\nlast yaer." !== $content ) {
				continue;
			}
			$response['feedback'] = array(
				array(
					'block_id'  => $block['clientId'] ?? '',
					'category'  => 'grammar',
					'severity'  => 'suggestion',
					'title'     => 'yaer → year',
					'feedback'  => 'Spelling.',
					'quick_fix' => array(
						'find'    => "40%\nlast yaer",
						'replace' => "40%\nlast year",
					),
				),
			);
		}
		return $response;
	},
	10,
	2
);

// A note that mentions Fresh Eyes and asks to simplify gets an edit that
// shortens the text the note is on, and names that text so tests can tell
// the selection reached the AI. Asking for sources gets an answer with a
// made-up link, which the plugin must take out.
add_filter(
	'ai_feedback_mock_reply',
	static function ( string $reply, string $prompt ): string {
		if (
			! str_contains( $prompt, 'mentioned you, Fresh Eyes' )
			|| ! preg_match( '/NEW USER REPLY:\n(.*?)\n\n/s', $prompt, $asked )
		) {
			return $reply;
		}

		if ( preg_match( '/link to the sources/i', $asked[1] ) ) {
			return (string) wp_json_encode(
				array(
					'reply' => 'See https://made-up.example/study for the numbers.',
					'edit'  => null,
				)
			);
		}

		if ( ! preg_match( '/simplify/i', $asked[1] ) || ! preg_match( '/^Block content: (.*)$/m', $prompt, $block ) ) {
			return $reply;
		}

		$selected = preg_match( "/^SELECTED TEXT \\(the part of the block the user's note is about\\):\n(.+)$/m", $prompt, $match ) ? $match[1] : '';
		$find     = '' !== $selected ? $selected : 'very long and winding';
		if ( ! str_contains( $block[1], $find ) ) {
			return $reply;
		}

		return (string) wp_json_encode(
			array(
				'reply' => '' !== $selected ? "Simplified \"{$selected}\"." : 'Simplified the paragraph.',
				'edit'  => array(
					'type'    => 'replace_text',
					'find'    => $find,
					'replace' => 'short',
				),
			)
		);
	},
	30,
	2
);

// A reply in a persona thread answers as that persona, so tests can see the
// reply prompt kept the note's voice.
add_filter(
	'ai_feedback_mock_reply',
	static function ( string $reply, string $prompt ): string {
		if ( ! preg_match( '/^- You gave the original feedback as this reader: ([^-\n]+?) - /m', $prompt, $persona ) ) {
			return $reply;
		}

		return 'Still speaking as the ' . $persona[1] . ': link the benchmark.';
	},
	40,
	2
);

// A reply that mentions sentence case states a standing preference, so the
// mock reply offers to remember it as a house style rule.
add_filter(
	'ai_feedback_mock_reply',
	static function ( string $reply, string $prompt ): string {
		if (
			! preg_match( '/NEW USER REPLY:\n(.*?)\n\n/s', $prompt, $asked )
			|| ! preg_match( '/sentence case/i', $asked[1] )
		) {
			return $reply;
		}

		return (string) wp_json_encode(
			array(
				'reply'    => 'Got it, sentence case it is.',
				'edit'     => null,
				'remember' => 'Headings use sentence case, except product names such as WordPress and Gutenberg, which keep their own capital letters',
			)
		);
	},
	40,
	2
);

// A review whose prompt carries the sentence case house style rule flags the
// first block for breaking it, so tests can see a saved rule reach the
// next review.
add_filter(
	'ai_feedback_mock_review',
	static function ( array $response, array $blocks, string $prompt = '' ): array {
		$block = $blocks[0] ?? null;
		if ( ! str_contains( $prompt, "HOUSE STYLE:\n" ) || ! preg_match( '/^- "Headings use sentence case\b/mi', $prompt ) || ! is_array( $block ) ) {
			return $response;
		}

		$response['feedback'][] = array(
			'block_id' => $block['clientId'] ?? '',
			'category' => 'tone',
			'severity' => 'suggestion',
			'title'    => 'Follow the house style',
			'feedback' => 'House style: headings use sentence case.',
		);
		return $response;
	},
	30,
	3
);

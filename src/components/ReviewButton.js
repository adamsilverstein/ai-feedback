/**
 * Review Button Component
 */
import { Button } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

/**
 * Review Button component.
 *
 * @return {JSX.Element} Review button component.
 */
export default function ReviewButton() {
	const { postId, isReviewing, selectedModel, focusAreas, targetTone } =
		useSelect(
			( select ) => ( {
				postId: select( editorStore ).getCurrentPostId(),
				isReviewing: select( STORE_NAME ).isReviewing(),
				selectedModel: select( STORE_NAME ).getSelectedModel(),
				focusAreas: select( STORE_NAME ).getFocusAreas(),
				targetTone: select( STORE_NAME ).getTargetTone(),
			} ),
			[]
		);

	const { startReview } = useDispatch( STORE_NAME );

	const handleReview = async () => {
		if ( ! postId ) {
			return;
		}

		try {
			await startReview( {
				postId,
				model: selectedModel,
				focusAreas,
				targetTone,
			} );
		} catch ( error ) {
			// Error is already in the store
			console.error( 'Review failed:', error );
		}
	};

	const isDisabled = ! postId || isReviewing;

	return (
		<div className="ai-feedback-review-button">
			<Button
				variant="primary"
				onClick={ handleReview }
				disabled={ isDisabled }
				isBusy={ isReviewing }
			>
				{ isReviewing
					? __( 'Reviewing...', 'ai-feedback' )
					: __( 'Review Document', 'ai-feedback' ) }
			</Button>
			{ ! postId && (
				<p className="description">
					{ __(
						'Save your post first to enable review',
						'ai-feedback'
					) }
				</p>
			) }
			{ isReviewing && (
				<p className="description">
					{ __(
						'AI is analyzing your content...',
						'ai-feedback'
					) }
				</p>
			) }
		</div>
	);
}

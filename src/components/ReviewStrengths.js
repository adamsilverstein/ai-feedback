/**
 * Review Strengths Component
 *
 * Lists what the draft already does well, each linked to its block. These
 * stay in the sidebar rather than becoming notes, so there is nothing to
 * resolve.
 */
import { Button } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useRef } from '@wordpress/element';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { navigateToBlock } from '../utils/navigate-to-block';
import { findStrengthBlock, getStrengths } from '../utils/strengths';

/**
 * Review strengths component.
 *
 * @param {Object} props           Component props.
 * @param {Array}  props.strengths Strengths from the review.
 * @return {Element|null} Strengths list, or null when there are none.
 */
export default function ReviewStrengths({ strengths }) {
	const items = getStrengths(strengths);
	if (items.length === 0) {
		return null;
	}

	return (
		<section
			className="ai-feedback-strengths"
			aria-labelledby="ai-feedback-strengths-heading"
		>
			<h4
				id="ai-feedback-strengths-heading"
				className="ai-feedback-strengths-heading"
			>
				{__('What’s working', 'fresh-eyes')}
			</h4>
			<ul className="ai-feedback-strengths-list">
				{items.map((strength) => (
					<li
						key={strength.block_id}
						className="ai-feedback-strength"
					>
						<span
							className="ai-feedback-strength-icon"
							aria-hidden="true"
						>
							✓
						</span>
						<StrengthText strength={strength} />
					</li>
				))}
			</ul>
		</section>
	);
}

/**
 * A strength's text, as a link to its block while the block can be found.
 *
 * @param {Object} props          Component props.
 * @param {Object} props.strength Strength.
 * @return {Element} Text or link.
 */
function StrengthText({ strength }) {
	// Finding the block after a reload reads every block's text, so it is
	// looked for again only when blocks are added or removed, such as once
	// the post loads, or when the block found is edited. The click looks
	// again, as a block edited back to its reviewed text is not noticed.
	const found = useRef({});
	const hasBlock = useSelect(
		(select) => {
			const { getGlobalBlockCount, getBlock } = select(blockEditorStore);
			const blockCount = getGlobalBlockCount();
			const last = found.current;
			const block = last.clientId ? getBlock(last.clientId) : null;
			if (
				last.strength !== strength ||
				last.blockCount !== blockCount ||
				last.block !== block
			) {
				const clientId =
					blockCount > 0 ? findStrengthBlock(strength) : null;
				found.current = {
					strength,
					blockCount,
					clientId,
					block: clientId ? getBlock(clientId) : null,
				};
			}
			return !!found.current.clientId;
		},
		[strength]
	);

	const goToBlock = () => {
		const clientId = findStrengthBlock(strength);
		if (clientId) {
			navigateToBlock(clientId);
		}
	};

	if (!hasBlock) {
		return (
			<span className="ai-feedback-strength-text">{strength.text}</span>
		);
	}

	return (
		<Button
			variant="link"
			className="ai-feedback-strength-text"
			onClick={goToBlock}
		>
			{strength.text}
		</Button>
	);
}

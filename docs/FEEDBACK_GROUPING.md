# Feedback Grouping and Deduplication Feature

## Overview
This feature automatically groups similar feedback items to reduce visual noise and help users identify patterns efficiently. When the AI detects the same issue across multiple blocks, these items are grouped together with a clear count and expandable view.

## User Experience

### Before Grouping
```
🟡 Fix passive voice
Block 1 → 

🟡 Fix passive voice
Block 2 →

🟡 Fix passive voice
Block 3 →

🟡 Fix passive voice
Block 4 →
```
**Problem:** Repetitive, cluttered interface that makes it hard to see the big picture.

### After Grouping
```
🟡 Fix passive voice (4 occurrences) ▼
[Expanded view shows:]
  This paragraph uses passive voice. Consider rewriting in active voice.
  
  Affected blocks:
  • Block 1 →
  • Block 2 →
  • Block 3 →
  • Block 4 →
```
**Benefit:** Clean, organized view that shows patterns and enables efficient batch fixing.

## Technical Implementation

### Backend (PHP)

#### Response Parser
The `Response_Parser` class now includes grouping logic:

```php
// Grouping happens during parse_feedback()
$parsed = $this->parse_feedback($ai_response, $blocks);
// Returns grouped feedback with is_group, count, block_ids

// Grouping algorithm
1. Normalize titles (lowercase, preserve word boundaries)
2. Create key: category + normalized_title
3. Group items with same key
4. Keep highest severity
5. Track all affected block IDs
```

#### Notes Manager
Stores grouping metadata in WordPress comment meta:
- `is_group`: Boolean flag
- `group_count`: Number of grouped items
- `group_block_ids`: JSON array of block IDs
- `original_title`: Title before count suffix

### Frontend (JavaScript)

#### Component Structure
```
ReviewSummary
  └── FeedbackList
       └── FeedbackItem (wrapper)
            ├── SingleFeedbackItem (for individual items)
            └── GroupedFeedbackItem (for groups)
                 ├── Expandable header with count
                 ├── Feedback content
                 └── List of affected blocks
```

#### Key Features
1. **Expand/Collapse**: Groups start collapsed, click to expand
2. **Block Navigation**: Each block has a "Go to block" button
3. **Severity Badges**: Visual indicators (🔴 🟡 🟢)
4. **Accessibility**: Proper ARIA labels and keyboard navigation

## Grouping Rules

### Items are grouped when:
✅ Same category (content, tone, flow, design)
✅ Similar normalized title
✅ Multiple occurrences detected

### Items are NOT grouped when:
❌ Different categories
❌ Different normalized titles
❌ Only one occurrence

### Severity Handling
When grouping, the **highest severity** is preserved:
```
suggestion + critical → critical
important + suggestion → important
```

## Examples

### Example 1: Passive Voice
**Input:**
- Block A: "Fix passive voice" (suggestion)
- Block B: "Fix passive voice" (important)
- Block C: "Fix passive voice" (suggestion)

**Output:**
- Group: "Fix passive voice (3 occurrences)" (important)
- Block IDs: [A, B, C]

### Example 2: Mixed Issues
**Input:**
- Block A: "Fix spelling" (content)
- Block B: "Fix passive voice" (tone)
- Block C: "Fix spelling" (content)

**Output:**
- Group 1: "Fix spelling (2 occurrences)" (content)
- Single: "Fix passive voice" (tone)

### Example 3: Similar Titles
**Input:**
- Block A: "Fix passive voice!"
- Block B: "Fix Passive Voice"
- Block C: "fix-passive-voice"

**Output:**
- Group: "Fix passive voice (3 occurrences)"
- All titles normalize to: "fix_passive_voice"

## Testing

### Unit Tests
Location: `tests/php/ResponseParserGroupingTest.php`

Tests cover:
- ✅ Grouping similar items
- ✅ Different categories not grouped
- ✅ Highest severity preserved
- ✅ Title normalization
- ✅ Block metadata preservation
- ✅ Empty input handling
- ✅ Single item not marked as group

### Manual Testing Checklist
- [ ] Create duplicate feedback items
- [ ] Verify grouping in UI
- [ ] Test expand/collapse
- [ ] Test block navigation
- [ ] Verify severity display
- [ ] Test with different categories
- [ ] Test with similar titles (punctuation variations)

## Performance Considerations

### Backend
- Grouping is O(n) where n = number of feedback items
- Typically < 20 items per review
- Negligible performance impact

### Frontend
- Groups render collapsed by default (lazy rendering)
- React keys properly managed for re-renders
- No performance concerns with typical usage

## Accessibility

### Screen Readers
- Proper ARIA labels for groups
- Expandable state announced
- Block navigation clearly labeled

### Keyboard Navigation
- Tab through all interactive elements
- Enter/Space to expand/collapse
- Arrow keys within lists

## Future Enhancements

### Potential Improvements
1. User preference to disable grouping
2. Custom grouping thresholds
3. Auto-expand groups with critical severity
4. Bulk action buttons (fix all, dismiss all)
5. Visual diff showing common patterns

## Migration Notes

### Backward Compatibility
- Existing feedback items work normally (not grouped)
- No database migrations required
- New notes include grouping metadata
- Old notes missing metadata are treated as single items

## Related Files

### PHP
- `includes/class-response-parser.php` - Grouping logic
- `includes/class-notes-manager.php` - Metadata storage
- `tests/php/ResponseParserGroupingTest.php` - Unit tests

### JavaScript
- `src/components/FeedbackItem.js` - Display component
- `src/components/FeedbackList.js` - List wrapper
- `src/components/ReviewSummary.js` - Integration
- `src/index.scss` - Styling

## Support

For questions or issues:
1. Check existing issues on GitHub
2. Review test cases for examples
3. See DESIGN.md for architecture details

Here is the complete Design Document and Specification for the **AI Reviewer** plugin. This project leverages the new WordPress 6.9 features (Notes, Abilities API) and the AI Building Blocks.

### **Project: AI Reviewer for Gutenberg**

**Status:** Draft | **Target Version:** WordPress 6.9+

-----

### **1. Executive Summary**

The **AI Reviewer** plugin serves as an intelligent editorial assistant within the WordPress Block Editor. It leverages the **PHP AI Client** to analyze post content and utilizes the **Notes API** to attach contextual, block-level feedback directly to the document. The system supports a conversational feedback loop: if a user replies to an AI note, the AI analyzes the response and context to provide further guidance.

### **2. Architecture Overview**

The plugin consists of three main layers:

1.  **Editor UI (React/JS):** A sidebar panel for configuration and triggering reviews. It interacts with the Block Editor stores to read content and inject Notes.
2.  **Orchestration (PHP):** Endpoints to handle the "Review" request, prompt engineering, and interaction with the AI Client. It also handles the "Reply" logic via hooks.
3.  **AI Integration:** Utilizes the `WordPress\AI_Client` to communicate with the chosen model (e.g., GPT-4o, Claude 3.5 Sonnet) and the **Abilities API** to register the reviewer as an autonomous agent.

#### **High-Level Data Flow**

1.  **Trigger:** User clicks "Review Document" in the Plugin Sidebar.
2.  **Payload:** The plugin serializes the current blocks (content, structure, and active context) and sends them to the REST API.
3.  **Analysis:** The Server uses the `AI_Client` to process the document against the selected "Persona" (e.g., "Strict Editor", "Design Critic").
4.  **Response:** The AI returns structured JSON containing feedback items, each linked to a specific Block ID or content snippet.
5.  **Action:** The React frontend receives the feedback and programmatically creates **Notes** attached to the relevant blocks using the `core/editor` data store.

-----

### **3. Technical Specification**

#### **3.1 User Interface (UI)**

  * **Location:** Plugin Sidebar (`PluginSidebar` from `@wordpress/edit-post`).
  * **Controls:**
      * **Model Selection:** Dropdown to override the global default (e.g., fast review vs. deep reasoning).
      * **Review Mode:**
          * *Editorial:* Focus on grammar, tone, flow.
          * *Design:* Focus on layout, spacing, color contrast (analyzing block attributes).
          * *SEO:* Focus on keywords and structure.
      * **"Review Now" Button:** Initiates the process with a loading state.
      * **Progress Indicator:** Shows current status (Sending, Analyzing, Creating Notes).

#### **3.2 Notes Integration**

  * **Creation:** The plugin uses the internal `dispatch('core')` actions or REST API to create notes.
  * **Block Association:**
      * Per the WP 6.9 specs, Notes are linked via the `noteId` in block attributes.
      * **Mechanism:**
        1.  AI identifies the target block (by ClientID or index).
        2.  System creates a Note via REST API (`POST /wp/v2/comments`).
        3.  System updates the Block's `attributes` to include `{ metadata: { noteId: <new_id> } }`.
  * **Author Identity:** The AI's notes will be attributed to a special "AI Reviewer" user or the system user, identified by specific comment meta (`_is_ai_generated: true`).

#### **3.3 Conversation Loop (Replies)**

  * **Trigger:** Hook into `wp_insert_comment` (or the specific Notes hook `wp_rest_insert_comment`).
  * **Logic:**
    1.  Check if the new comment is a **reply** to a note.
    2.  Check if the **parent note** has `_is_ai_generated: true`.
    3.  If yes, schedule an async action (Action Scheduler) `ai_reviewer_process_reply`.
  * **Execution:**
      * The async job fetches the original block content, the AI's previous note, and the user's reply.
      * It sends this context to the AI Model.
      * The AI generates a reply.
      * The system inserts the AI's reply as a child comment to the discussion.

#### **3.4 Data Model (AI Response Schema)**

The AI will be instructed to return JSON conforming to this schema:

```json
{
  "feedback": [
    {
      "block_index": 3,
      "type": "editorial",
      "sentiment": "constructive",
      "message": "This paragraph is passive. Consider rewriting to active voice.",
      "suggestion": "The team launched the product..."
    },
    {
      "block_index": 5,
      "type": "design",
      "message": "The contrast on this group block background might be too low for accessibility."
    }
  ]
}
```

-----

### **4. Agents & Abilities API (`agents.md`)**

We will register the AI Reviewer as an "Agent" using the new **Abilities API**. This allows other AI agents (e.g., a "Project Manager" agent) to discover and utilize the reviewer.

````markdown
# Agents & Abilities Configuration

## Reviewer Ability
Registered via `wp_register_ability` to expose the reviewing capability to the system.

### Ability: `ai-reviewer/review-content`
* **Description:** "Reviews post content for editorial, design, and SEO improvements."
* **Category:** `content-optimization`
* **Input Schema:**
    * `content` (string): The HTML/Block content to review.
    * `mode` (string): 'editorial', 'design', or 'seo'.
* **Output Schema:**
    * `feedback` (array): List of feedback items containing `block_index` and `message`.

### Code Implementation
```php
function register_ai_reviewer_abilities() {
    if ( ! function_exists( 'wp_register_ability' ) ) return;

    wp_register_ability( 'ai-reviewer/review-content', [
        'label'           => __( 'Review Content', 'ai-reviewer' ),
        'description'     => __( 'Analyzes content and returns structured feedback.', 'ai-reviewer' ),
        'category'        => 'content-optimization',
        'input_schema'    => [
            'type'       => 'object',
            'properties' => [
                'content' => [ 'type' => 'string' ],
                'mode'    => [ 'type' => 'string', 'enum' => ['editorial', 'design', 'seo'] ]
            ]
        ],
        'execute_callback' => [ 'AI_Reviewer_Agent', 'execute_review' ]
    ]);
}
add_action( 'wp_abilities_api_init', 'register_ai_reviewer_abilities' );
````

-----

### **5. Testing Strategy**

The project requires rigorous testing due to its interaction with AI (non-deterministic) and the Editor DOM.

#### **5.1 Unit Tests (PHP & JS)**

  * **PHP (PHPUnit):**
      * Test `AI_Reviewer_Agent::execute_review` mocks the `AI_Client` to ensure it parses JSON correctly.
      * Test the `wp_insert_comment` hook correctly identifies AI threads and schedules the async job.
  * **JS (Jest):**
      * Test the logic that maps `block_index` from the API response to actual `clientId` in the editor.
      * Test the UI state management (loading, error handling).

#### **5.2 End-to-End (E2E) Tests (Playwright)**

  * **Scenarios:**
    1.  **Full Review:** Open a post -\> Click "Review" -\> Wait for API -\> Assert "Notes" icons appear on blocks.
    2.  **Reply Flow:** Open an AI Note -\> Type a reply -\> Submit -\> Wait for "AI is typing..." indicator (or reload) -\> Assert AI reply appears.
  * **Mocking:**
      * **Crucial:** Do not call real AI APIs in E2E. Mock the `/wp-json/ai-reviewer/v1/review` endpoint to return a fixed JSON payload. This ensures tests are stable and cost-free.

#### **5.3 Visual Regression Tests**

  * **Goal:** Ensure the "Plugin Panel" and the "Note" markers do not break the editor layout.
  * **Tool:** Playwright with snapshot comparisons.
  * **Snapshot:** Capture the editor state after "Notes" are populated to ensure they align correctly with blocks.

-----

### **6. Implementation Guide: Core Class Structure**

**`includes/class-ai-orchestrator.php`**

  * Handles the API endpoint `POST /wp-json/ai-reviewer/v1/analyze`.
  * Constructs the prompt for the `AI_Client`.
      * *Prompt Strategy:* System prompt must enforce the JSON schema. "You are a standard WordPress editor. Analyze the provided HTML. Return ONLY JSON."

**`includes/class-note-manager.php`**

  * Handles the reply logic.
  * `add_action('wp_insert_comment', ...)`
  * Uses `WordPress\AI_Client\Prompt_Builder` to construct the "Reply" prompt: "User replied: 'X'. Context: 'Y'. Draft a helpful response."

**`src/index.js` (React)**

  * Uses `@wordpress/data` to `select('core/block-editor').getBlocks()`.
  * Sends blocks to the backend.
  * On response, iterates feedback:
    ```javascript
    feedback.forEach( item => {
       const block = blocks[item.block_index];
       // Dispatch action to create Note linked to block.clientId
    });
    ```

### **7. Dependencies**

  * **PHP AI Client:** `wordpress/php-ai-client` (Composer)
  * **Gutenberg:** `wordpress/block-editor`, `wordpress/components`
  * **Core:** WordPress 6.9+ (Beta/RC required for Notes API development)


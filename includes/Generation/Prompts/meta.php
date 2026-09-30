<?php
/**
 * Default meta/FAQ prompt template (user message). Overridable via settings.
 *
 * Allowed variables: {keyword} {language} {tone} {title} {full_text_excerpt}
 *
 * @package SCP
 */

return <<<'PROMPT'
Finalize SEO metadata for an article.

Title: "{title}"
Primary keyword: "{keyword}"
Language: {language}. Tone: {tone}.

Article excerpt:
{full_text_excerpt}

Output strict JSON with this exact shape:
{
  "meta_title": "SEO title tag, 50-60 characters, contains the keyword",
  "meta_description": "Meta description, 140-155 characters, contains the keyword once, has a clear benefit or hook",
  "excerpt": "One-sentence post excerpt, max 40 words",
  "faq": [
    {"question": "Question from the article FAQ", "answer": "Concise 2-3 sentence answer"}
  ]
}
- meta_title must be <= 60 characters, meta_description <= 155 characters.
- FAQ answers must be self-contained and quotable on their own.
- Do not include any text outside the JSON object.
PROMPT;

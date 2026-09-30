<?php
/**
 * Default section-writing prompt template (user message). Overridable via settings.
 *
 * Allowed variables: {keyword} {language} {tone} {title} {heading} {outline_summary}
 *                    {prev_summary} {section_words}
 *
 * @package SCP
 */

return <<<'PROMPT'
Write one section of an SEO article.

Article title: "{title}"
Primary keyword: "{keyword}"
Language: {language}. Tone: {tone}.

Full outline (for context, do not write other sections):
{outline_summary}

Section to write now: "{heading}"
Target length for this section: about {section_words} words.

What has been covered so far (avoid repeating it):
{prev_summary}

Requirements:
- Output clean HTML only: start with the <h2> for "{heading}", use <h3>, <p>, <ul>/<ol>, <strong>, <table> as appropriate. No <h1>.
- If the heading is a question, answer it directly in the first 1-2 sentences before expanding.
- Include concrete details, examples, numbers, dates, named tools, prices, or step counts — no generic filler.
- Use the keyword or a close variant naturally once or twice; do not stuff it.
- No transitional fluff like "In this section", "As we have seen", "Let's dive in".
- Vary paragraph length: alternate short 1-2 sentence paragraphs with longer 4-6 sentence ones; never write three paragraphs of identical length in a row.
- Vary sentence openers — do not start 3+ consecutive sentences with the same word or phrase.
- End with substance, not with a hollow summary ("In conclusion...").
- Output HTML only, no markdown fences, no commentary.
PROMPT;

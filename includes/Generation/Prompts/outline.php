<?php
/**
 * Default outline prompt template (user message). Overridable via settings.
 *
 * Allowed variables: {keyword} {language} {tone} {word_count} {serp_titles} {paa_questions}
 *
 * @package SCP
 */

return <<<'PROMPT'
Create a detailed SEO article outline for the keyword: "{keyword}".

Requirements:
- Language: {language}. Tone: {tone}.
- Target total length: about {word_count} words.
- Output strict JSON with this exact shape:
{
  "title": "Compelling H1 title that naturally contains the keyword",
  "slug": "url-friendly-slug",
  "sections": [
    {"heading": "H2 heading", "points": ["key point to cover", "another point"]}
  ],
  "faq": ["question one?", "question two?"]
}
- 5 to 8 top-level sections. Use question-style H2 headings where they fit naturally.
- The first section must be an introduction that directly answers the core query in 2-3 sentences before expanding.
- Include a practical/how-to section and a comparison or data-driven section where relevant.
- The "faq" array must contain 3 to 5 real questions people ask about this topic.
- Reference these competing page titles for structure inspiration (do not copy): {serp_titles}
- Work these "People Also Ask" questions into the outline or FAQ where relevant: {paa_questions}
- Do not include any text outside the JSON object.
PROMPT;

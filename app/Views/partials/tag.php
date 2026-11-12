<?php
/* Colored status tag: always colour AND text. Vars: $class (pending|confirmed|overdue|balance|...), $label (or $status) */
$tagClass = (string) ($class ?? $status ?? 'neutral');
$tagLabel = (string) ($label ?? ucfirst(str_replace('_', ' ', $tagClass)));
?>
<span class="tag tag-<?= e($tagClass) ?>"><?= e($tagLabel) ?></span>

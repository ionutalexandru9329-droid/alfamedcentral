<?php
/** @var string $richName */
/** @var string $richLabel */
/** @var string $richValue */
use App\Core\View;
$editorId='rte_'.preg_replace('/[^a-z0-9_]+/i','_',($richName??'editor')).'_'.substr(sha1(($richName??'').($richLabel??'')),0,6);
?>
<label class="rich-field"><span class="field-label"><?=View::e($richLabel??'Text')?></span>
<textarea class="rich-editor-source" id="<?=View::e($editorId)?>_source" name="<?=View::e($richName)?>" hidden><?=View::e($richValue??'')?></textarea>
<div class="rich-editor" data-rich-editor data-source-id="<?=View::e($editorId)?>_source">
    <div class="rich-toolbar" role="toolbar" aria-label="Editor text">
        <select data-rich-command="formatBlock" title="Stil paragraf"><option value="P">Paragraf</option><option value="H1">Titlu H1</option><option value="H2">Titlu H2</option><option value="H3">Subtitlu H3</option><option value="H4">Subtitlu H4</option><option value="BLOCKQUOTE">Citat</option></select>
        <select data-rich-command="fontName" title="Font"><option value="Arial">Arial</option><option value="Verdana">Verdana</option><option value="Georgia">Georgia</option><option value="Tahoma">Tahoma</option><option value="Trebuchet MS">Trebuchet</option><option value="Times New Roman">Times New Roman</option></select>
        <span class="rich-toolbar-divider"></span>
        <button type="button" data-rich-command="bold" title="Bold"><b>B</b></button>
        <button type="button" data-rich-command="italic" title="Italic"><i>I</i></button>
        <button type="button" data-rich-command="underline" title="Subliniat"><u>U</u></button>
        <button type="button" data-rich-command="strikeThrough" title="Taiat"><s>S</s></button>
        <span class="rich-toolbar-divider"></span>
        <button type="button" data-rich-command="insertUnorderedList" title="Lista cu puncte">&#8226; Lista</button>
        <button type="button" data-rich-command="insertOrderedList" title="Lista numerotata">1. Lista</button>
        <button type="button" data-rich-command="outdent" title="Micsoreaza indentarea">&#8592;</button>
        <button type="button" data-rich-command="indent" title="Mareste indentarea">&#8594;</button>
        <span class="rich-toolbar-divider"></span>
        <button type="button" data-rich-action="link" title="Insereaza link">&#128279;</button>
        <button type="button" data-rich-command="unlink" title="Elimina link">Link x</button>
        <button type="button" data-rich-action="image" title="Insereaza imagine din URL">&#128444;</button>
        <button type="button" data-rich-command="insertHorizontalRule" title="Linie orizontala">&#8213;</button>
        <span class="rich-toolbar-divider"></span>
        <label class="rich-color" title="Culoare text">A<input type="color" data-rich-color="foreColor" value="#172033"></label>
        <label class="rich-color" title="Culoare fundal">&#9635;<input type="color" data-rich-color="hiliteColor" value="#fff3a3"></label>
        <button type="button" data-rich-command="justifyLeft" title="Aliniere stanga">&#8801;</button>
        <button type="button" data-rich-command="justifyCenter" title="Centrat">&#8803;</button>
        <button type="button" data-rich-command="justifyRight" title="Aliniere dreapta">&#8801;&#8594;</button>
        <span class="rich-toolbar-divider"></span>
        <button type="button" data-rich-command="undo" title="Undo">&#8630;</button>
        <button type="button" data-rich-command="redo" title="Redo">&#8631;</button>
        <button type="button" data-rich-command="removeFormat" title="Elimina formatarea">Tx</button>
        <button type="button" data-rich-action="source" title="Editeaza HTML">HTML</button>
        <button type="button" data-rich-action="fullscreen" title="Editor mare">&#x26F6;</button>
    </div>
    <div class="rich-editor-body" contenteditable="true" data-rich-body aria-label="<?=View::e($richLabel??'Editor')?>"></div>
    <textarea class="rich-editor-html" data-rich-html hidden aria-label="Sursa HTML"></textarea>
</div>
</label>

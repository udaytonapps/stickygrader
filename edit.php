<?php
require_once "../config.php";

use \Tsugi\Util\U;
use \Tsugi\Util\Net;
use \Tsugi\Core\LTIX;
use \Tsugi\Core\Settings;
use \Tsugi\UI\SettingsForm;
use \Tsugi\Blob\BlobUtil;
use \CloudConvert\Models\Task;

// No parameter means we require CONTEXT, USER, and LINK
$LAUNCH = LTIX::requireData();

$upload_max_size_bytes = BlobUtil::maxUploadBytes();
$upload_max_size = U::displaySize($upload_max_size_bytes);

// If settings were updated
if ( SettingsForm::handleSettingsPost() ) {
    header( 'Location: '.addSession('edit.php') ) ;
    return;
}

// Sanity check input
if ($_SERVER['REQUEST_METHOD'] == 'POST' && count($_POST) < 1 ) {
    $_SESSION['error'] = 'File upload size exceeded, please re-upload a smaller file';
    error_log("Upload size exceeded");
    header('Location: '.addSession('edit.php'));
    return;
}

if ( count($_FILES) > 1 ) {
    $_SESSION['error'] = 'Only one file allowed';
    header( 'Location: '.addSession('edit.php') ) ;
    return;
}

// Check all files to be within our size limit
$thefdes = null;
foreach($_FILES as $fdes) {
    if ( $fdes['size'] > $upload_max_size_bytes ) {
        $_SESSION['error'] = 'Error - '.$fdes['name'].' has a size of '.$fdes['size'].' (' . $upload_max_size . ' max size per file)';
        header( 'Location: '.addSession('edit.php') ) ;
        return;
    }
    $thefdes = $fdes;
}

if ( $thefdes ) {
    $file_id = BlobUtil::uploadToBlob($thefdes);
    $_SESSION['success'] = "File Uploaded";
    $LAUNCH->result->setJsonKey('file_id', $file_id);
    header( 'Location: '.addSession('edit.php') ) ;
    return;
}

$file_id = $LAUNCH->result->getJsonKey('file_id');

$next = U::safe_href(U::get($_GET, 'next', 'edit.php'));
$user_id = U::safe_href(U::get($_GET, 'user_id'));
if ( $user_id && ! $LAUNCH->user->instructor ) {
    http_response_code(404);
    die('Not authorized');
}
if ( ! $user_id ) $user_id = $LAUNCH->user->id;

$inst_note = $LAUNCH->result->getNote($user_id );

// Load and parse the old JSON
$json = $LAUNCH->result->getJsonForUser($user_id);
$json = json_decode($json);
if ( $json == null ) $json = new \stdClass();
$lock = isset($json->lock) && $json->lock;

$menu = new \Tsugi\UI\MenuSet();
if ( $file_id ) {
    $menu->addLeft(__('View'), 'index.php');
} else {
    $menu->addLeft(__('Please upload your file'), false);
}

if ( $LAUNCH->user->instructor ) {
    $submenu = new \Tsugi\UI\Menu();
    $submenu->addLink(__('Student Data'), 'grades');
    $submenu->addLink(__('Settings'), '#', /* push */ false, SettingsForm::attr());
    if ( $CFG->launchactivity ) {
        $submenu->addLink(__('Analytics'), 'analytics');
    }
    $menu->addRight(__('Help'), '#', /* push */ false, 'data-toggle="modal" data-target="#helpModal"');
    $menu->addRight(__('Instructor'), $submenu, /* push */ false);
} else {
    if ( strlen($inst_note) > 0 ) $menu->addRight(__('Note'), '#', /* push */ false, 'data-toggle="modal" data-target="#noteModal"');
    $menu->addRight(__('Help'), '#', /* push */ false, 'data-toggle="modal" data-target="#helpModal"');
    $menu->addRight(__('Settings'), '#', /* push */ false, SettingsForm::attr());
}


// Render view
$OUTPUT->header();
// https://github.com/jitbit/HtmlSanitizer

$OUTPUT->bodyStart();
$OUTPUT->topNav($menu);
$OUTPUT->flashMessages();

SettingsForm::start();
?>
<p>
This program makes use of the following technologies:
<ul>
<li> Online conversion from PDF to HTML
<a href="https://cloudconvert.com" target="_blank">CloudConvert API</a> </li>
<li>Open Source JavaScript annotation software from 
<a href="https://annotatorjs.org/" target="_blank">Annotator JS</a></li>
</ul>
</p>
<?php if ( $LAUNCH->user->instructor ) { ?>
<p>
The CloudConvert API requires a license.  There are free licenses available
for a few conversions per day.  Use the <b>Configure</b> option to set your
license key across all the links for a particular class.
</p>
<?php
} // instructor

SettingsForm::done();
SettingsForm::end();

$OUTPUT->helpModal("PDF Annotation Tool",
    "You can upload a PDF document with this tool.  You and your teacher can annotate your document with colored notes.");

if ( strlen($inst_note) > 0 ) {
    echo($OUTPUT->modalString(__("Instructor Note"), htmlentities($inst_note), "noteModal"));
}

if ( $file_id ) {
    if ( count($annotations) > 0 ) {
        echo("<p>".__('Annotations:').' '.count($annotations)."</p>\n");
    }
    echo("<p>Your file has been uploaded.</p>\n");

    $OUTPUT->footer();
    return;
}

?>
<span class="fa fa-file-pdf-o fa-3x" style="color: var(--primary); float:right;"></span>
<form action="<?= addSession('edit.php') ?>" method="post" id="upload_form" enctype="multipart/form-data">
<p>
<label class="btn btn-default">
    <input type="file" class="file-upload" id="thepdf" name="result_<?= $LAUNCH->result->id ?>">
</label>
</p>
<p>
    <input type="submit" id="submit" class="btn btn-primary" value="Submit"> (Max size <?= $upload_max_size ?>) 
    <span id="spinner" style="display:none;"><img src="<?= $OUTPUT->getSpinnerUrl() ?>"/></span>
</p>
</form>
<p>
Please select a PDF file to upload.  
<?php
$OUTPUT->footerStart();
?>
<!-- https://stackoverflow.com/questions/2472422/django-file-upload-size-limit -->
<script>
$("#upload_form").submit(function(e) {
    console.log('Checking file size');
    if (window.File && window.FileReader && window.FileList && window.Blob) {
        var file = $('#thepdf')[0].files[0];
        if ( typeof file == 'undefined' ) {
            alert("Please select a file");
            e.preventDefault();
            return;
        }
        if ( file.type != 'application/pdf') {
            console.log('Type', file.type);
             alert("File " + file.name + " expecting PDF, found " + file.type );
            e.preventDefault();
            return;
        }
        if (file && file.size > <?= $upload_max_size_bytes ?> ) {
            alert("File " + file.name + " of type " + file.type + " must be < <?= $upload_max_size ?>");
            e.preventDefault();
            return;
        }
        $("#spinner").show();
        $("#submit").attr("disabled", true);
        return;  // Allow POST to happen
    }
    e.preventDefault();
});
</script>
<?php
$OUTPUT->footerEnd();


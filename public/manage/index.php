<?php
require_once __DIR__ . '/../../misuzu.php';

$templating = $app->getTemplating();

switch ($_GET['v'] ?? null) {
    case 'overview':
        echo $templating->render('@manage.general.overview');
        break;

    case 'logs':
        echo 'soon';
        break;

    case 'emoticons':
        echo 'soon as well';
        break;

    case 'settings':
        echo 'somewhat soon i guess';
        break;

    case null:
        header('Location: ?v=overview');
        break;
}
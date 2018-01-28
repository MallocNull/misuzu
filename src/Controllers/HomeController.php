<?php
namespace Misuzu\Controllers;

use Misuzu\Application;
use Misuzu\Database;

class HomeController extends Controller
{
    public function index(): string
    {
        $app = Application::getInstance();
        $twig = $app->templating;

        return $twig->render('home.landing');
    }
}

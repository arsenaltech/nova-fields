<?php

namespace R64\NovaFields;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Nova\Nova;
use Laravel\Nova\Tool as BaseTool;
use Laravel\Nova\Menu\MenuSection;

class FilemanagerTool extends BaseTool
{
    /**
     * Perform any tasks that need to happen when the tool is booted.
     *
     * @return void
     */
    public function boot()
    {
        Nova::script('nova-fields', __DIR__.'/../dist/js/field.js');
    }

    /**
     * Build the view that renders the navigation links for the tool.
     *
     * @return View
     */
    public function renderNavigation()
    {
        return view('nova-fields::navigation');
    }

    public function menu(Request $request)
    {
        return MenuSection::make(__(config('nova-filemanager.navigation_label', 'FileManager')))
            ->path('/' . config('nova-filemanager.path', 'nova-filemanager'));
    }
}

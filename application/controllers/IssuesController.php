<?php

namespace Icinga\Module\Eventtracker\Controllers;

use Icinga\Authentication\Auth;
use Icinga\Module\Eventtracker\Db\EventSummaryBySeverity;
use Icinga\Module\Eventtracker\Web\Table\IssuesTable;
use Icinga\Module\Eventtracker\Web\Widget\AdditionalTableActions;
use Icinga\Module\Eventtracker\Web\Widget\SeverityFilter;
use Icinga\Module\Eventtracker\Web\Widget\ToggleSeverities;
use Icinga\Module\Eventtracker\Web\Widget\ToggleStatus;
use Icinga\Web\Widget\Tabextension\DashboardAction;
use ipl\Html\Html;

class IssuesController extends Controller
{
    use IssuesFilterHelper;

    public function indexAction()
    {
        $this->setAutorefreshInterval(20);
        $db = $this->db();

        $table = new IssuesTable($db, $this->url());
        $this->applyFilters($table);
        if (! $this->url()->getParam('sort')) {
            $this->url()->setParam('sort', 'severity DESC');
        }
        $filters = Html::tag('ul', ['class' => 'nav'], [
            // Order & ensureAssembled matters!
            // temporarily disabled, should be configurable:
            // (new TogglePriorities($this->url()))->applyToQuery($table->getQuery())->ensureAssembled(),
            (new ToggleSeverities($this->url()))->applyToQuery($table->getQuery())->ensureAssembled(),
            (new ToggleStatus($this->url()))->applyToQuery($table->getQuery())->ensureAssembled(),
        ]);
        $sevSummary = new EventSummaryBySeverity($table->getQuery());
        $summary = new SeverityFilter($sevSummary->fetch($db), $this->url());

        if ($this->showCompact()) {
            $table->setNoHeader();
            $table->showCompact();
            $table->getQuery()->limit(1000);
            $this->content()->add($table);
        } else {
            if (! $this->params->get('wide')) {
                $table->showCompact();
            }
            $this->addSingleTab('Issues');
            $this->setTitle('Event Tracker');
            $this->controls()->addTitle('Current Issues', $summary);
            $this->actions()->add($filters);
            $this->actions()->add($this->createViewToggle());
            $table->getQuery()->limit(1000);
            (new AdditionalTableActions($table, Auth::getInstance(), $this->url()))
                ->appendTo($this->actions());
            $this->eventuallySendJson($table);
            $table->renderTo($this);
        }

        if (! $this->showCompact()) {
            $this->tabs()->extend(new DashboardAction());
        }
    }
}

<?php

namespace UKModules\ADIF;

use REDCap;

// Mixed into UKModules\ADIF\ADIF. What the current user can do with ADIF in a project, from their
// REDCap user rights there. Three levels, each including everything the one below it allows:
//   design (ADIF::ACCESS_DESIGN) - Project Design and Setup rights, or a super user: change the API
//            sources, uploaded tables, field mappings and the module's settings
//   edit   (ADIF::ACCESS_EDIT)   - "View & Edit" data viewing rights on every instrument that has ADIF
//            mappings (any instrument, while none has): resolve errors on the Import Status page -
//            retry and dismiss failed runs, run imports that never ran, run the queue - since those
//            write the mapped fields. Data Sources and Mapping are view-only at this level.
//   read   (ADIF::ACCESS_READ)   - "Read Only" (or better) on at least one instrument: see every
//            ADIF page, change nothing
// Anyone else (no data viewing rights on any instrument) gets no ADIF pages or menu links.
// Separately, a user in a data access group only sees, and can only act on, their group's records on
// the Import Status page (see adifVisibleRecords()); that applies at every level, design included.
// Every action re-checks on the server, so revoking a right takes effect on the next request.
trait Access
{
    // [project id => ['level' => ADIF::ACCESS_*, 'group_id' => int|null]], for this request
    private $adifAccessCache = [];

    // [project id => [record => true] | null], for this request
    private $adifVisibleRecordsCache = [];

    // REDCap's data viewing levels that allow editing: View & Edit, and View & Edit plus editing
    // survey responses. Read Only is 2 and No Access 0.
    private static $adifEditFormRights = [1, 3];

    // The current user's ADIF level in $projectId (one of the ADIF::ACCESS_* constants)
    public function accessLevel($projectId)
    {
        return $this->adifUserAccess($projectId)['level'];
    }

    public function canViewModule($projectId)
    {
        return $this->accessLevel($projectId) >= self::ACCESS_READ;
    }

    // Retrying, dismissing and running imports from the Import Status page
    public function canResolveImports($projectId)
    {
        return $this->accessLevel($projectId) >= self::ACCESS_EDIT;
    }

    // Changing the module's configuration, and so the API sources it calls, the tables uploaded on the
    // Data Sources page and the field mappings
    public function canConfigureApiSources($projectId)
    {
        return $this->accessLevel($projectId) >= self::ACCESS_DESIGN;
    }

    // The records the current user may see on the Import Status page, as [record => true], or null
    // when they may see all of them (they aren't in a data access group)
    public function adifVisibleRecords($projectId)
    {
        $projectId = (int) $projectId;
        if (array_key_exists($projectId, $this->adifVisibleRecordsCache)) {
            return $this->adifVisibleRecordsCache[$projectId];
        }

        $groupId = $this->adifUserAccess($projectId)['group_id'];
        $visible = null;
        if ($groupId !== null) {
            $recordIdField = $this->getRecordIdField($projectId);
            $data = json_decode(REDCap::getData([
                'project_id' => $projectId,
                'return_format' => 'json',
                'fields' => [$recordIdField],
                'groups' => [$groupId],
            ]), true);
            if (!is_array($data)) {
                // Better to show the user nothing than another group's records
                throw new \Exception('ADIF couldn\'t read which records are in your data access group.');
            }
            $visible = [];
            foreach ($data as $row) {
                $visible[(string) ($row[$recordIdField] ?? '')] = true;
            }
            unset($visible['']);
        }

        return $this->adifVisibleRecordsCache[$projectId] = $visible;
    }

    public function canSeeRecord($projectId, $record)
    {
        $visible = $this->adifVisibleRecords($projectId);
        return $visible === null || isset($visible[(string) $record]);
    }

    // $rows (each with a 'record') without the ones whose record the current user can't see
    public function onlyVisibleRecords($projectId, array $rows)
    {
        $visible = $this->adifVisibleRecords($projectId);
        if ($visible === null) {
            return $rows;
        }
        return array_values(array_filter($rows, fn($row) => isset($visible[(string) ($row['record'] ?? '')])));
    }

    // Ends a module page, after its header, for users who can't see ADIF in the project. The framework
    // already refuses linked pages to them (see ADIF::redcap_module_link_check_display); this keeps
    // each page safe on its own, should its link be renamed or removed.
    public function requireModuleView($projectId)
    {
        if ($this->canViewModule($projectId)) {
            return;
        }
        echo '<div class="red" style="max-width: 800px; margin: 20px 0;">You don\'t have access to ADIF in this project.'
            . ' It needs at least Read Only data viewing rights on one of the project\'s instruments; ask a user with'
            . ' User Rights privileges in this project.</div>';
        require APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
        exit;
    }

    // The instruments with at least one field mapped to an API field
    public function mappedInstruments($projectId)
    {
        $forms = [];
        foreach ($this->getProjectSetting('field-mappings', $projectId) ?: [] as $form => $fields) {
            foreach ((array) $fields as $config) {
                if (trim((string) ($config['mapping'] ?? '')) !== '') {
                    $forms[] = (string) $form;
                    break;
                }
            }
        }
        return $forms;
    }

    private function adifUserAccess($projectId)
    {
        $projectId = (int) $projectId;
        if (isset($this->adifAccessCache[$projectId])) {
            return $this->adifAccessCache[$projectId];
        }

        $access = ['level' => self::ACCESS_NONE, 'group_id' => null];
        try {
            $access = $this->adifLookUpAccess($projectId);
        } catch (\Throwable $e) {
            // getUser() throws when nobody is logged in; an error reading rights grants nothing
            error_log("ADIF: couldn't read the current user's rights in project $projectId: " . $e->getMessage());
        }

        return $this->adifAccessCache[$projectId] = $access;
    }

    private function adifLookUpAccess($projectId)
    {
        $access = ['level' => self::ACCESS_NONE, 'group_id' => null];
        if ($projectId < 1) {
            return $access;
        }

        $user = $this->getUser();
        if (!$user) {
            return $access;
        }
        if ($user->isSuperUser()) {
            $access['level'] = self::ACCESS_DESIGN;
            return $access;
        }

        $rights = $user->getRights($projectId);
        if (!is_array($rights) || empty($rights)) {
            return $access;   // not a user in this project
        }

        $groupId = $rights['group_id'] ?? null;
        $access['group_id'] = is_numeric($groupId) && (int) $groupId > 0 ? (int) $groupId : null;

        if ($user->hasDesignRights($projectId)) {
            $access['level'] = self::ACCESS_DESIGN;
            return $access;
        }

        $formRights = $this->adifFormRights($rights, $user->getUsername(), $projectId);
        $canEditForm = fn($form) => in_array($formRights[$form] ?? 0, self::$adifEditFormRights, true);

        // Mapped instruments that no longer exist (no rights row) don't count either way
        $mapped = array_values(array_filter($this->mappedInstruments($projectId),
            fn($form) => array_key_exists($form, $formRights)));
        $canEdit = empty($mapped)
            ? !empty(array_filter(array_keys($formRights), $canEditForm))
            : count(array_filter($mapped, $canEditForm)) === count($mapped);

        if ($canEdit) {
            $access['level'] = self::ACCESS_EDIT;
        } elseif (!empty(array_filter($formRights, fn($level) => $level > 0))) {
            $access['level'] = self::ACCESS_READ;
        }

        return $access;
    }

    // [instrument => data viewing level] from a user's rights. The framework's getRights() returns
    // REDCap's rights row, where they're packed into 'data_entry' as "[form,level][form,level]";
    // REDCap::getUserRights() unpacks them into 'forms', and either shape is read here.
    private function adifFormRights(array $rights, $username, $projectId)
    {
        if (isset($rights['forms']) && is_array($rights['forms'])) {
            return array_map('intval', $rights['forms']);
        }

        $forms = [];
        if (preg_match_all('/\[([a-z0-9_]+),(\d+)/i', (string) ($rights['data_entry'] ?? ''), $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $forms[$match[1]] = (int) $match[2];
            }
        }

        // Neither shape: fall back to REDCap's own, which only answers for the current project
        if (empty($forms) && defined('PROJECT_ID') && (int) PROJECT_ID === (int) $projectId) {
            $userRights = REDCap::getUserRights($username);
            $row = $userRights[$username] ?? reset($userRights);
            if (is_array($row) && is_array($row['forms'] ?? null)) {
                $forms = array_map('intval', $row['forms']);
            }
        }

        return $forms;
    }
}

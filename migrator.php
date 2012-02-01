<?php

/**
  * This script migrate redmine project from one instance of redmine to another one. It will
  * import the following objects:
  *   - project
  *   - categories
  *   - versions
  *   - issues
  *   - journals
  *   - logged time
  *   - modules status (wether it is active for the project)
  *   - documents
  *   - wikis
  *
  * It relies on manual mapping for users and priorities as they should already exist in target database.
  * All modifications of the target database are live. So it is strongly advised to test against a dummy database first.
  *
  * USAGE:
  *   1. update manual mapping for users, priorities and enumerations (see $usersMapping, $prioritiesMapping and $enumerationsMapping)
  *   2. update database connection data and project ID to migrate
  *   3. run the script
  */

require_once(dirname(__FILE__) . '/Library/DBMysql.php');

class Migrator {
  var $dbOld = null;
  var $dbNew = null;

  var $usersMapping = array(
    1 => 10,
    5 => 35,
    7 => 10,
    6 => 10,
  );

  var $prioritiesMapping = array(
    15 => 3,
    16 => 4,
    17 => 5,
    18 => 6,
    19 => 7,
  );

  var $enumerationsMapping = array(
    1 => 1,
    2 => 2,
  );

  var $issueStatusesMapping = array(
  );

  var $trackersMapping = array(
  );

  var $projectsMapping = array();
  var $categoriesMapping = array();
  var $versionsMapping = array();
  var $journalsMapping = array();
  var $issuesMapping = array();
  var $timeEntriesMapping = array();
  var $modulesMapping = array();
  var $documentsMapping = array();
  var $wikisMapping = array();
  var $wikiPagesMapping = array();
  var $wikiContentsMapping = array();
  var $wikiContentVersionsMapping = array();
  var $attachmentsMapping = array();
  var $attachmentsList = array();

  function __construct($host1, $db1, $user1, $pass1, $host2, $db2, $user2, $pass2) {
    $this->dbOld = new DBMysql($host1, $user1, $pass1);
    $this->dbOld->connect($db1);

    $this->dbNew = new DBMysql($host2, $user2, $pass2);
    $this->dbNew->connect($db2);
  }

  private function replaceUser($idUserOld) {
    if ($idUserOld == null)
      return null;

    if (!isset($this->usersMapping[$idUserOld]))
      throw new Exception("No mapping defined for old user id '$idUserOld'");
    else
      return $this->usersMapping[$idUserOld];
  }

  private function replacePriority($idPriorityOld) {
    if ($idPriorityOld == null)
      return null;

    if (!isset($this->prioritiesMapping[$idPriorityOld]))
      throw new Exception("No mapping defined for old priority id '$idPriorityOld'");
    else
      return $this->prioritiesMapping[$idPriorityOld];
  }

  private function migrateCategories($idProjectOld) {
    $result = $this->dbOld->select('issue_categories', array('project_id' => $idProjectOld));
    $categoriesOld = $this->dbOld->getAssocArrays($result);
    foreach ($categoriesOld as $categoryOld) {
      $idCategoryOld = $categoryOld['id'];
      unset($categoryOld['id']);
      $categoryOld['project_id'] = $this->projectsMapping[$idProjectOld];
      $categoryOld['assigned_to_id'] = $this->replaceUser($categoryOld['assigned_to_id']);

      $idCategoryNew = $this->dbNew->insert('issue_categories', $categoryOld);
      $this->categoriesMapping[$idCategoryOld] = $idCategoryNew;
    }
  }

  private function migrateVersions($idProjectOld) {
    $result = $this->dbOld->select('versions', array('project_id' => $idProjectOld));
    $versionsOld = $this->dbOld->getAssocArrays($result);
    foreach ($versionsOld as $versionOld) {
      $idVersionOld = $versionOld['id'];
      unset($versionOld['id']);
      $versionOld['project_id'] = $this->projectsMapping[$idProjectOld];

      $idVersionNew = $this->dbNew->insert('versions', $versionOld);
      $this->versionsMapping[$idVersionOld] = $idVersionNew;

      $this->migrateAttachments('Version', $idVersionOld, $idVersionNew);
    }
  }

  private function migrateJournals($idIssueOld) {
    $result = $this->dbOld->select('journals', array('journalized_id' => $idIssueOld));
    $journalsOld = $this->dbOld->getAssocArrays($result);
    foreach ($journalsOld as $journal) {
      $idJournalOld = $journal['id'];
      unset($journal['id']);

      // Update fields
      $journal['user_id'] = $this->replaceUser($journal['user_id']);
      $journal['journalized_id'] = $this->issuesMapping[$idIssueOld];

      $idJournalNew = $this->dbNew->insert('journals', $journal);
      $this->journalsMapping[$idJournalOld] = $idJournalNew;

      $this->migrateJournalsDetails($idJournalOld);
    }
  }

  private function migrateTimeEntries($idProjectOld) {
    $result = $this->dbOld->select('time_entries', array('project_id' => $idProjectOld));
    $timeEntriesOld = $this->dbOld->getAssocArrays($result);
    foreach ($timeEntriesOld as $timeEntry) {
      $idTimeEntryOld = $timeEntry['id'];
      unset($timeEntry['id']);

      // Update fields
      $timeEntry['project_id'] = $this->projectsMapping[$timeEntry['project_id']];
      $timeEntry['issue_id'] = $this->issuesMapping[$timeEntry['issue_id']];
      $timeEntry['user_id'] = $this->replaceUser($timeEntry['user_id']);

      $idTimeEntryNew = $this->dbNew->insert('time_entries', $timeEntry);
      $this->timeEntriesMapping[$idTimeEntryOld] = $idTimeEntryNew;
    }
  }

  private function migratemodules($idProjectOld) {
    $result = $this->dbOld->select('enabled_modules', array('project_id' => $idProjectOld));
    $modulesOld = $this->dbOld->getAssocArrays($result);
    foreach ($modulesOld as $module) {
      $idModuleOld = $module['id'];
      unset($module['id']);

      // Update fields
      $module['project_id'] = $this->projectsMapping[$module['project_id']];

      $idModuleNew = $this->dbNew->insert('enabled_modules', $module);
      $this->modulesMapping[$idModuleOld] = $idModuleNew;
    }
  }

  private function migrateJournalsDetails($idJournalOld) {
    $result = $this->dbOld->select('journal_details', array('journal_id' => $idJournalOld));
    $journalDetailsOld = $this->dbOld->getAssocArrays($result);
    foreach ($journalDetailsOld as $journalDetail) {
      unset($journalDetail['id']);

      // Update fields
      $journalDetail['journal_id'] = $this->journalsMapping[$idJournalOld];

      $this->dbNew->insert('journal_details', $journalDetail);
    }
  }

  private function migrateIssues($idProjectOld) {
    $result = $this->dbOld->select('issues', array('project_id' => $idProjectOld));
    $issuesOld = $this->dbOld->getAssocArrays($result);
    foreach ($issuesOld as $issueOld) {
      $idIssueOld = $issueOld['id'];
      unset($issueOld['id']);

      // Update fields for new version of issue
      $issueOld['project_id'] = $this->projectsMapping[$idProjectOld];
      $issueOld['tracker_id'] = $this->trackersMapping[$issueOld['tracker_id']];
      $issueOld['status_id'] = $this->issueStatusesMapping[$issueOld['status_id']];
      $issueOld['assigned_to_id'] = $this->replaceUser($issueOld['assigned_to_id']);
      $issueOld['author_id'] = $this->replaceUser($issueOld['author_id']);
      $issueOld['priority_id'] = $this->replacePriority($issueOld['priority_id']);
      if ($issueOld['fixed_version_id']) $issueOld['fixed_version_id'] = $this->versionsMapping[$issueOld['fixed_version_id']];

      $idIssueNew = $this->dbNew->insert('issues', $issueOld);
      $this->issuesMapping[$idIssueOld] = $idIssueNew;

      $this->migrateJournals($idIssueOld);

      $this->migrateAttachments('Issue', $idIssueOld, $idIssueNew);
    }
  }

  private function migrateDocuments($idProjectOld) {
    $result = $this->dbOld->select('documents', array('project_id' => $idProjectOld));
    $documentsOld = $this->dbOld->getAssocArrays($result);
    foreach ($documentsOld as $documentOld) {
      $idDocumentOld = $documentOld['id'];
      unset($documentOld['id']);

      // Update fields for new version of document
      $documentOld['project_id'] = $this->projectsMapping[$idProjectOld];
      $documentOld['category_id'] = $this->enumerationsMapping[$documentOld['category_id']];

      $idDocumentNew = $this->dbNew->insert('documents', $documentOld);
      $this->documentsMapping[$idDocumentOld] = $idDocumentNew;

      $this->migrateAttachments('Document', $idDocumentOld, $idDocumentNew);
    }
  }

  private function migrateWikis($idProjectOld) {
    $result = $this->dbOld->select('wikis', array('project_id' => $idProjectOld));
    $wikisOld = $this->dbOld->getAssocArrays($result);
    foreach ($wikisOld as $wikiOld) {
      $idWikiOld = $wikiOld['id'];
      unset($wikiOld['id']);

      // Update fields for new version of wiki
      $wikiOld['project_id'] = $this->projectsMapping[$idProjectOld];

      $idWikiNew = $this->dbNew->insert('wikis', $wikiOld);
      $this->wikisMapping[$idWikiOld] = $idWikiNew;

      $this->migrateWikiPages($idWikiOld);
    }
  }

  private function migrateWikiPages($idWikiOld, $idParent = null) {
    $result = $this->dbOld->select('wiki_pages', array('wiki_id' => $idWikiOld, 'parent_id' => empty($idParent) ? 'NULL' : $idParent));
    $wikiPagesOld = $this->dbOld->getAssocArrays($result);
    foreach ($wikiPagesOld as $wikiPageOld) {
      $idWikiPageOld = $wikiPageOld['id'];
      unset($wikiPageOld['id']);

      // Update fields for new version of wiki_page
      $wikiPageOld['wiki_id'] = $this->wikisMapping[$idWikiOld];

      $idWikiPageNew = $this->dbNew->insert('wiki_pages', $wikiPageOld);
      $this->wikiPagesMapping[$idWikiPageOld] = $idWikiPageNew;

      $this->migrateWikiContents($idWikiPageOld);
      $this->migrateWikiPages($idWikiOld, $idWikiPageOld);

      $this->migrateAttachments('WikiPage', $idWikiPageOld, $idWikiPageNew);
    }
  }

  private function migrateWikiContents($idWikiPageOld) {
    $result = $this->dbOld->select('wiki_contents', array('page_id' => $idWikiPageOld));
    $wikiContentsOld = $this->dbOld->getAssocArrays($result);
    foreach ($wikiContentsOld as $wikiContentOld) {
      $idWikiContentOld = $wikiContentOld['id'];
      unset($wikiContentOld['id']);

      // Update fields for new version of wiki_content
      $wikiContentOld['page_id'] = $this->wikiPagesMapping[$idWikiPageOld];

      $idWikiContentNew = $this->dbNew->insert('wiki_contents', $wikiContentOld);
      $this->wikiContentsMapping[$idWikiContentOld] = $idWikiContentNew;

      $this->migrateWikiContentVersions($idWikiContentOld);
    }
  }

  private function migrateWikiContentVersions($idWikiContentOld) {
    $result = $this->dbOld->select('wiki_content_versions', array('wiki_content_id' => $idWikiContentOld));
    $wikiContentVersionsOld = $this->dbOld->getAssocArrays($result);
    foreach ($wikiContentVersionsOld as $wikiContentVersionOld) {
      $idWikiContentVersionOld = $wikiContentVersionOld['id'];
      unset($wikiContentVersionOld['id']);

      // Update fields for new version of wiki_content
      $wikiContentVersionOld['wiki_content_id'] = $this->wikiContentsMapping[$idWikiContentOld];

      $idWikiContentVersionNew = $this->dbNew->insert('wiki_content_versions', $wikiContentVersionOld);
      $this->wikiContentVersionsMapping[$idWikiContentVersionOld] = $idWikiContentVersionNew;
    }
  }

  private function migrateAttachments($containerType, $oldContainerId, $newContainerId) {
    $result = $this->dbOld->select('attachments', array('container_id' => $oldContainerId, 'container_type' => $containerType));
    $attachmentsOld = $this->dbOld->getAssocArrays($result);
    foreach ($attachmentsOld as $attachmentOld) {
      $idAttachmentOld = $attachmentOld['id'];
      unset($attachmentOld['id']);

      // Update fields for new version of attachment
      $attachmentOld['container_id'] = $newContainerId;
      $attachmentOld['author_id'] = $this->usersMapping[$attachmentOld['author_id']];

      $idAttachmentNew = $this->dbNew->insert('attachments', $attachmentOld);
      $this->attachmentsMapping[$idAttachmentOld] = $idAttachmentNew;

      $this->attachmentsList[] = $attachmentOld['disk_filename'];
    }
  }

  function migrateProject($idProjectOld) {
    $result = $this->dbOld->select('projects', array('id' => $idProjectOld));
    $projectsOld = $this->dbOld->getAssocArrays($result);

    foreach ($projectsOld as $projectOld) {
      unset($projectOld['id']);
      $idProjectNew = $this->dbNew->insert('projects', $projectOld);
      $this->projectsMapping[$idProjectOld] = $idProjectNew;

      $this->migrateCategories($idProjectOld);
      $this->migrateVersions($idProjectOld);
      $this->migrateIssues($idProjectOld);
      $this->migrateTimeEntries($idProjectOld);
      $this->migrateModules($idProjectOld);
      $this->migrateDocuments($idProjectOld);
      $this->migrateWikis($idProjectOld);
      $this->migrateAttachments('Project', $idProjectOld, $idProjectNew);
    }

    file_put_contents('attachments.txt', join("\n", $this->attachmentsList) . "\n");

    echo 'projects: ' . count($this->projectsMapping) . " <br>\n";
    echo 'issues: ' . count($this->issuesMapping) . " <br>\n";
    echo 'categories: ' . count($this->categoriesMapping) . " <br>\n";
    echo 'versions: ' . count($this->versionsMapping) . " <br>\n";
    echo 'journals: ' . count($this->journalsMapping) . " <br>\n";
    echo 'time entries: ' . count($this->timeEntriesMapping) . " <br>\n";
    echo 'modules enabled: ' . count($this->modulesMapping) . " <br>\n";
    echo 'documents: ' . count($this->documentsMapping) . " <br>\n";
    echo 'wikis: ' . count($this->wikisMapping) . " <br>\n";
    echo 'wiki pages: ' . count($this->wikiPagesMapping) . " <br>\n";
    echo 'wiki contents: ' . count($this->wikiContentsMapping) . " <br>\n";
    echo 'wiki content versions: ' . count($this->wikiContentVersionsMapping) . " <br>\n";
    echo 'attachments: ' . count($this->attachmentsMapping) . " (see attachments.txt) <br>\n";
  }
}

$migrator = new Migrator(
  'localhost', 'old_redmine', 'root', 'password',
  'localhost', 'new_redmine', 'root', 'password'
);

$migrator->migrateProject(4);

?>


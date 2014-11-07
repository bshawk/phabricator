<?php

$table = new PhrictionDocument();
$conn_w = $table->establishConnection('w');

echo "Populating Phriction policies.\n";

$default_view_policy = PhabricatorPolicies::getMostOpenPolicy();
$default_edit_policy = PhabricatorPolicies::POLICY_USER;

foreach (new LiskMigrationIterator($table) as $doc) {
  $id = $doc->getID();

  if ($doc->getViewPolicy() && $doc->getEditPolicy()) {
    echo "Skipping doc $id; already has policy set.\n";
    continue;
  }

  // project documents get the project policy
  if (PhrictionDocument::isProjectSlug($doc->getSlug())) {

    $project_slug =
      PhrictionDocument::getProjectSlugIdentifier($doc->getSlug());
    $project_slugs = array($project_slug);
    $project = id(new PhabricatorProjectQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPhrictionSlugs($project_slugs)
      ->executeOne();

    if ($project) {

      $view_policy = nonempty($project->getViewPolicy(), $default_view_policy);
      $edit_policy = nonempty($project->getEditPolicy(), $default_edit_policy);

      $project_name = $project->getName();
      echo "Migrating doc $id to project policy $project_name...\n";
      $doc->setViewPolicy($view_policy);
      $doc->setEditPolicy($edit_policy);
      $doc->save();
      continue;
    }
  }

  echo "Migrating doc $id to default install policy...\n";
  $doc->setViewPolicy($default_view_policy);
  $doc->setEditPolicy($default_edit_policy);
  $doc->save();
}

echo "Done.\n";

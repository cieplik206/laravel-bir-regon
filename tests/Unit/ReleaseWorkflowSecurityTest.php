<?php

declare(strict_types=1);

function releaseWorkflow(): string
{
    $contents = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/release.yml');

    if ($contents === false) {
        throw new RuntimeException('Unable to read the release workflow.');
    }

    return $contents;
}

it('runs the privileged publisher only from the trusted default branch', function (): void {
    $workflow = releaseWorkflow();

    expect($workflow)
        ->toContain("repository_dispatch:\n    types:\n      - publish-release")
        ->toContain('if [[ "$GITHUB_EVENT_NAME" != "repository_dispatch" ]]')
        ->toContain('if [[ "$GITHUB_REF" != "refs/heads/$DEFAULT_BRANCH" ]]')
        ->toContain('if [[ "$GITHUB_ACTOR" != "$GITHUB_REPOSITORY_OWNER" ]]')
        ->toContain('if [[ "$GITHUB_TRIGGERING_ACTOR" != "$GITHUB_REPOSITORY_OWNER" ]]')
        ->not->toContain("push:\n    tags:")
        ->not->toContain('github.ref_name');
});

it('binds the signed tag object to its requested ref and target commit', function (): void {
    $workflow = releaseWorkflow();

    expect(substr_count($workflow, 'gh api "repos/$GITHUB_REPOSITORY/git/ref/tags/$RELEASE_TAG"'))
        ->toBe(2)
        ->and(substr_count($workflow, 'jq -r \'.tag\' <<<"$tag_json"'))
        ->toBe(2)
        ->and($workflow)
        ->toContain('EXPECTED_TAG_OBJECT_SHA: ${{ steps.tag.outputs.tag_object_sha }}')
        ->toContain('jq -r \'.object.sha\' <<<"$ref_json")" != "$EXPECTED_TAG_OBJECT_SHA"')
        ->toContain('jq -r \'.object.sha\' <<<"$tag_json")" != "$TARGET_SHA"');
});

it('requires CI success from the exact workflow and release target', function (): void {
    $workflow = releaseWorkflow();

    expect($workflow)
        ->toContain('actions/workflows/ci.yml/runs?event=push&status=completed&head_sha=$TARGET_SHA&per_page=100')
        ->toContain('.path == ".github/workflows/ci.yml"')
        ->toContain('.head_sha == $target')
        ->toContain('.head_branch == $branch')
        ->toContain('.event == "push"')
        ->toContain('.head_repository.full_name == $repository')
        ->toContain('actions/runs/$ci_run_id/jobs?filter=latest&per_page=100')
        ->toContain('.name == "CI Passed"')
        ->toContain('] | length == 1');
});

it('revalidates the remote tag immediately before publishing', function (): void {
    $workflow = releaseWorkflow();

    expect($workflow)->toMatch(
        '/Build source archives and checksums[\s\S]+'
        .'Revalidate tag and publish immutable GitHub release[\s\S]+'
        .'gh release create "\$RELEASE_TAG"/',
    );
});

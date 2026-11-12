<?php

use App\Enums\OrgRole;
use App\Models\App;
use App\Models\AttributeDefinition;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Segment;

beforeEach(function () {
    $this->org = Organization::factory()->create(['slug' => 'acme']);
    $this->clientApp = App::factory()->for($this->org)->create();
    AttributeDefinition::query()->create(['app_id' => $this->clientApp->id, 'key' => 'gender', 'type' => 'string', 'label' => '性別']);
    $this->url = "/acme/apps/{$this->clientApp->id}/segments";
});

it('creates a segment and previews counts', function () {
    actingAsOrgMember($this->org, OrgRole::Editor);
    Device::factory()->count(3)->for($this->clientApp)->create(['platform' => 'ios']);
    Device::factory()->for($this->clientApp)->create(['platform' => 'android']);
    $conditions = ['op' => 'and', 'conditions' => [['field' => 'device.platform', 'operator' => 'eq', 'value' => 'ios']]];

    $this->get("{$this->url}/create")->assertOk()->assertSee('attr.gender');
    $this->postJson("{$this->url}/preview", ['conditions' => $conditions])->assertOk()->assertJsonPath('devices', 3)->assertJsonPath('users', 0);
    $this->postJson("{$this->url}/preview", ['conditions' => ['op' => 'and', 'conditions' => [['field' => 'nope', 'operator' => 'eq', 'value' => 1]]]])->assertStatus(422);

    $this->post($this->url, ['name' => 'iOS', 'conditions' => json_encode($conditions)])->assertRedirect();
    $segment = Segment::query()->sole();
    expect($segment->conditions)->toEqual($conditions);
    $this->get("{$this->url}/{$segment->id}/edit")->assertOk();
});

it('validates conditions on save', function () {
    actingAsOrgMember($this->org, OrgRole::Editor);
    $this->post($this->url, ['name' => 'x', 'conditions' => '{"op":"and","conditions":[{"field":"attr.gender","operator":"gt","value":"a"}]}'])
        ->assertSessionHasErrors('conditions');
    $this->post($this->url, ['name' => 'x', 'conditions' => 'not json'])->assertSessionHasErrors('conditions');
});

it('forbids viewers from editing segments', function () {
    actingAsOrgMember($this->org, OrgRole::Viewer);
    $this->get($this->url)->assertOk();
    $this->post($this->url, ['name' => 'x', 'conditions' => '{}'])->assertForbidden();
});

it('cannot access a segment of another app', function () {
    actingAsOrgMember($this->org, OrgRole::Owner);
    $otherApp = App::factory()->for($this->org)->create();
    $seg = Segment::query()->create(['app_id' => $otherApp->id, 'name' => 'other', 'conditions' => ['op' => 'and', 'conditions' => []]]);
    $this->get("{$this->url}/{$seg->id}/edit")->assertNotFound();
    $this->delete("{$this->url}/{$seg->id}")->assertNotFound();
});

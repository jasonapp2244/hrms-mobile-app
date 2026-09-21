@extends('layouts.app')
@section('title', $rule->exists ? 'Edit Rule' : 'Add Rule')
@section('content')
<div class="d-md-flex d-block align-items-center justify-content-between page-breadcrumb mb-3">
  <div class="my-auto mb-2"><h2 class="mb-1">{{ $rule->exists ? 'Edit Rule' : 'Add Rule' }}</h2>
    <nav><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="ti ti-smart-home"></i></a></li>
      <li class="breadcrumb-item"><a href="{{ route('rules.index') }}">Policy Rules</a></li>
      <li class="breadcrumb-item active">{{ $rule->exists ? $rule->name : 'New' }}</li>
    </ol></nav></div>
</div>

@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<form method="POST" action="{{ $rule->exists ? route('rules.update', $rule) : route('rules.store') }}" id="ruleForm">
  @csrf
  @if($rule->exists)@method('PUT')@endif

  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" for="name">Name <span class="text-danger">*</span></label>
          <input type="text" name="name" id="name" class="form-control" maxlength="120" required
                 value="{{ old('name', $rule->name) }}">
          <div class="form-text">
            What you will recognise it by in the list. &ldquo;Night shift arrivals&rdquo; says why
            the rule exists; the conditions below say what it does.
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="trigger">When <span class="text-danger">*</span></label>
          <select name="trigger" id="trigger" class="form-select">
            @foreach($triggers as $value => $label)
              <option value="{{ $value }}" @selected(old('trigger', $rule->trigger) === $value)>{{ $label }}</option>
            @endforeach
          </select>
          <div class="form-text">
            The moment the rule is checked. Changing it changes what the rule can ask about.
          </div>
        </div>
      </div>

      <div class="form-check mt-3">
        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
               @checked(old('is_active', $rule->exists ? $rule->is_active : true))>
        <label class="form-check-label" for="is_active">
          Active — an inactive rule keeps its wording and never fires.
        </label>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between">
      <h5 class="mb-0">If</h5>
      <button type="button" class="btn btn-sm btn-outline-primary" id="addCondition">
        <i class="ti ti-plus me-1"></i>Add condition
      </button>
    </div>
    <div class="card-body">
      <p class="text-muted small">
        <strong>Every condition has to hold.</strong> There is no &ldquo;or&rdquo; on purpose —
        two rules say that, and each is legible on its own line in the list. A rule with no
        conditions at all fires every time, which is occasionally what you want.
      </p>
      <div id="conditions"></div>
      <div id="conditionsEmpty" class="text-muted small d-none">No conditions — this rule fires every time.</div>
      <div id="triggerNote" class="alert alert-warning mt-3 d-none"></div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between">
      <h5 class="mb-0">Then</h5>
      <button type="button" class="btn btn-sm btn-outline-primary" id="addAction">
        <i class="ti ti-plus me-1"></i>Add action
      </button>
    </div>
    <div class="card-body">
      <p class="text-muted small">
        Every action runs, in order. A rule <strong>cannot change a punch or decide a leave
        request</strong> — it tells somebody, and it can write a line on the activity trail.
        Notifications reach the app and the bell in here; email waits on the mail server being
        configured.
      </p>
      <div id="actions"></div>
      <div id="actionsEmpty" class="text-muted small d-none">
        A rule has to do something — add at least one action.
      </div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary">{{ $rule->exists ? 'Save Rule' : 'Create Rule' }}</button>
    <a href="{{ route('rules.index') }}" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>
@endsection

@push('scripts')
<script>
// The rule builder.
//
// The vocabulary comes from PolicyRule, through the controller, so the fields
// this form offers and the fields the engine understands cannot drift: there is
// one copy and this is a rendering of it.
//
// Rows are rebuilt from an array rather than cloned from a template, because a
// field's operators and its value control both depend on which field it is —
// cloning would mean patching three selects after the fact and getting one of
// them wrong.
(function () {
  const FIELDS    = @json($fields);
  const OPERATORS = @json($operators);
  const LOOKUPS   = @json($lookups);
  const ACTIONS   = @json($actions);
  const ROLES     = @json($roles);
  const NOTIFY_ROLE = @json(\App\Models\PolicyRule::ACTION_NOTIFY_ROLE);

  let conditions = @json(old('conditions', $rule->conditions ?? []));
  let actions    = @json(old('actions', $rule->actions ?? []));

  // old() hands back an object when a row was removed before posting.
  conditions = Object.values(conditions || []).filter(c => c && c.field);
  actions    = Object.values(actions || []).filter(a => a && a.type);

  const triggerSelect = document.getElementById('trigger');
  const conditionsBox = document.getElementById('conditions');
  const actionsBox    = document.getElementById('actions');
  const note          = document.getElementById('triggerNote');

  const fieldsFor = () => FIELDS[triggerSelect.value] || {};

  function option(value, label, selected) {
    const el = document.createElement('option');
    el.value = value;
    el.textContent = label;
    el.selected = String(selected) === String(value);
    return el;
  }

  function select(name, klass) {
    const el = document.createElement('select');
    el.name = name;
    el.className = 'form-select ' + (klass || '');
    return el;
  }

  /** The control a value is typed or picked in, which is what the field type decides. */
  function valueControl(field, name, value) {
    if (field.type === 'number') {
      const input = document.createElement('input');
      input.type = 'number';
      input.step = 'any';
      input.name = name;
      input.className = 'form-control';
      input.value = value ?? '';
      return input;
    }

    const el = select(name);
    const options = field.type === 'lookup' ? (LOOKUPS[field.lookup] || {}) : (field.options || {});

    el.appendChild(option('', 'Choose…', value === '' || value === null || value === undefined ? '' : null));

    Object.entries(options).forEach(([key, label]) => el.appendChild(option(key, label, value)));

    // A value the list no longer offers — a department deleted since the rule
    // was written. Kept and named, because silently dropping it would change
    // what the rule does without telling anybody.
    if (value !== undefined && value !== null && value !== '' && !(String(value) in options)) {
      el.appendChild(option(value, value + ' (no longer exists)', value));
    }

    return el;
  }

  function renderConditions() {
    const fields = fieldsFor();
    conditionsBox.innerHTML = '';

    conditions.forEach((condition, index) => {
      const field = fields[condition.field];
      const row = document.createElement('div');
      row.className = 'row g-2 align-items-start mb-2';

      // Field
      const fieldCol = document.createElement('div');
      fieldCol.className = 'col-md-4';
      const fieldSelect = select(`conditions[${index}][field]`);
      Object.entries(fields).forEach(([key, spec]) => fieldSelect.appendChild(option(key, spec.label, condition.field)));
      fieldSelect.addEventListener('change', () => {
        conditions[index] = { field: fieldSelect.value, operator: '', value: '' };
        renderConditions();
      });
      fieldCol.appendChild(fieldSelect);

      // Operator — only the ones this field's type allows.
      const opCol = document.createElement('div');
      opCol.className = 'col-md-3';
      const opSelect = select(`conditions[${index}][operator]`);
      Object.entries(OPERATORS)
        .filter(([, spec]) => field && spec.types.includes(field.type))
        .forEach(([key, spec]) => opSelect.appendChild(option(key, spec.label, condition.operator)));
      opSelect.addEventListener('change', () => { conditions[index].operator = opSelect.value; });
      opCol.appendChild(opSelect);

      // Value
      const valueCol = document.createElement('div');
      valueCol.className = 'col-md-4';
      if (field) {
        const control = valueControl(field, `conditions[${index}][value]`, condition.value);
        control.addEventListener('change', () => { conditions[index].value = control.value; });
        control.addEventListener('input', () => { conditions[index].value = control.value; });
        valueCol.appendChild(control);

        if (field.type === 'lookup' && Object.keys(LOOKUPS[field.lookup] || {}).length === 0) {
          const hint = document.createElement('div');
          hint.className = 'form-text text-warning';
          hint.textContent = 'Nothing to choose from yet — this company has none on record.';
          valueCol.appendChild(hint);
        }
      }
      // Whatever the operator select ended up on is what will post. Keep the
      // array in step so a row is never saved with an operator the screen is
      // not showing.
      if (opSelect.options.length) {
        conditions[index].operator = opSelect.value;
      }

      const removeCol = document.createElement('div');
      removeCol.className = 'col-md-1 d-grid';
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'btn btn-outline-danger';
      remove.innerHTML = '<i class="ti ti-x"></i>';
      remove.title = 'Remove this condition';
      remove.addEventListener('click', () => { conditions.splice(index, 1); renderConditions(); });
      removeCol.appendChild(remove);

      row.append(fieldCol, opCol, valueCol, removeCol);
      conditionsBox.appendChild(row);
    });

    document.getElementById('conditionsEmpty').classList.toggle('d-none', conditions.length > 0);
  }

  function renderActions() {
    actionsBox.innerHTML = '';

    actions.forEach((action, index) => {
      const row = document.createElement('div');
      row.className = 'row g-2 align-items-start mb-2';

      const typeCol = document.createElement('div');
      typeCol.className = 'col-md-5';
      const typeSelect = select(`actions[${index}][type]`);
      Object.entries(ACTIONS).forEach(([key, label]) => typeSelect.appendChild(option(key, label, action.type)));
      typeSelect.addEventListener('change', () => {
        actions[index] = { type: typeSelect.value };
        renderActions();
      });
      typeCol.appendChild(typeSelect);

      const roleCol = document.createElement('div');
      roleCol.className = 'col-md-6';
      if (action.type === NOTIFY_ROLE) {
        const roleSelect = select(`actions[${index}][role]`);
        roleSelect.appendChild(option('', 'Choose a role…', action.role ? null : ''));
        Object.entries(ROLES).forEach(([key, label]) => roleSelect.appendChild(option(key, label, action.role)));
        roleSelect.addEventListener('change', () => { actions[index].role = roleSelect.value; });
        roleCol.appendChild(roleSelect);
      }

      const removeCol = document.createElement('div');
      removeCol.className = 'col-md-1 d-grid';
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'btn btn-outline-danger';
      remove.innerHTML = '<i class="ti ti-x"></i>';
      remove.title = 'Remove this action';
      remove.addEventListener('click', () => { actions.splice(index, 1); renderActions(); });
      removeCol.appendChild(remove);

      row.append(typeCol, roleCol, removeCol);
      actionsBox.appendChild(row);
    });

    document.getElementById('actionsEmpty').classList.toggle('d-none', actions.length > 0);
  }

  document.getElementById('addCondition').addEventListener('click', () => {
    const first = Object.keys(fieldsFor())[0];
    if (!first) { return; }
    conditions.push({ field: first, operator: '', value: '' });
    renderConditions();
  });

  document.getElementById('addAction').addEventListener('click', () => {
    actions.push({ type: Object.keys(ACTIONS)[0] });
    renderActions();
  });

  // Changing the trigger changes the vocabulary. A condition asking about a
  // punch has no answer on a leave request, so it is dropped — and said out
  // loud, because a condition that disappears quietly is a rule that no longer
  // does what its author read on the way out.
  triggerSelect.addEventListener('change', () => {
    const fields = fieldsFor();
    const before = conditions.length;
    conditions = conditions.filter(c => c.field in fields);
    const dropped = before - conditions.length;

    note.classList.toggle('d-none', dropped === 0);
    if (dropped > 0) {
      note.textContent = dropped + (dropped === 1
        ? ' condition was removed — it asked about something this trigger has no answer for.'
        : ' conditions were removed — they asked about something this trigger has no answer for.');
    }

    renderConditions();
  });

  renderConditions();
  renderActions();
})();
</script>
@endpush

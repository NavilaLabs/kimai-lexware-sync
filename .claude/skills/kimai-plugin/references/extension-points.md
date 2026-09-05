# Extension points

Catalogue of everything a Kimai plugin can hook into. Class names, constructor signatures and event
names were read from Kimai **2.65.0** source, not from the documentation — the published docs are
stale in several places (they still mention `ConfigureAdminMenuEvent`, which no longer exists, and
`AbstractWidgetType`, which is superseded by `AbstractWidget`). Verify against the version you target:

```bash
ls ../../../src/Event/                                # from var/plugins/YourBundle/
grep -rln "AutoconfigureTag" ../../../src/
grep -n "public function" ../../../src/Event/<Event>.php
```

## Contents

- [How Kimai discovers your code](#how-kimai-discovers-your-code)
- [Event catalogue](#event-catalogue)
- [UI extension recipes](#ui-extension-recipes)
- [Configuration and permissions](#configuration-and-permissions)
- [Data extension recipes](#data-extension-recipes)
- [Auto-discovered interfaces](#auto-discovered-interfaces)
- [API endpoints](#api-endpoints)
- [Hooks for integration and sync plugins](#hooks-for-integration-and-sync-plugins)
- [What you cannot extend](#what-you-cannot-extend)

## How Kimai discovers your code

Three mechanisms, and picking the right one decides whether your class is ever called:

1. **Event subscribers** — implement `EventSubscriberInterface`; `autoconfigure: true` in your
   `services.yaml` tags them. Use for reacting to something Kimai does.
2. **Tagged interfaces** — a dozen interfaces carry `#[AutoconfigureTag]`, so implementing one is
   enough for Kimai to find it. Use for pluggable strategies Kimai looks up by ID.
3. **Container config prepending** — `PrependExtensionInterface::prepend()` writes into the `kimai`
   extension config at compile time. The only way to register permissions and extra template
   directories.

Anything else (controllers, commands, Twig extensions, form types, validators) is plain Symfony.

## Event catalogue

Subscribe with the class name as the event name — `ConfigureMainMenuEvent::class => ['method', 100]`.
The older string constants (`ThemeEvent::STYLESHEET`) still exist where the class dispatches several
distinct events.

**Timesheet** — `TimesheetCreatePreEvent`, `TimesheetCreatePostEvent`, `TimesheetUpdatePreEvent`,
`TimesheetUpdatePostEvent`, `TimesheetUpdateMultiplePreEvent`, `TimesheetUpdateMultiplePostEvent`,
`TimesheetDeletePreEvent`, `TimesheetDeleteMultiplePreEvent`, `TimesheetDuplicatePreEvent`,
`TimesheetDuplicatePostEvent`, `TimesheetRestartPreEvent`, `TimesheetRestartPostEvent`,
`TimesheetStopPreEvent`, `TimesheetStopPostEvent`, `TimesheetMetaDefinitionEvent`,
`TimesheetMetaDisplayEvent`, `TimesheetStatisticsQueryEvent`

**Customer / Project / Activity** — each has `…CreatePreEvent`, `…CreatePostEvent`,
`…UpdatePreEvent`, `…UpdatePostEvent`, `…DeleteEvent`, `…MetaDefinitionEvent`, `…MetaDisplayEvent`,
`…DetailControllerEvent`, `…StatisticEvent`; projects and activities additionally have
`…BudgetStatisticEvent`

**User** — `UserCreatePreEvent`, `UserCreatePostEvent`, `UserUpdatePreEvent`, `UserUpdatePostEvent`,
`UserDeletePreEvent`, `UserDeletePostEvent`, `UserPreferenceEvent`, `UserPreferenceDisplayEvent`,
`PrepareUserEvent`, `UserInteractiveLoginEvent`, `UserEmailEvent`, `UserRevenueStatisticEvent`

**Team** — `TeamCreatePreEvent`, `TeamCreatePostEvent`, `TeamUpdatePreEvent`, `TeamUpdatePostEvent`,
`TeamDeleteEvent`

**Invoice** — `InvoiceCreatedEvent`, `InvoiceDeleteEvent`, `InvoiceUpdatePreEvent`,
`InvoiceUpdatePostEvent`, `InvoicePreRenderEvent`, `InvoicePostRenderEvent`, `InvoiceDocumentsEvent`,
`InvoiceMetaDefinitionEvent`, `InvoiceMetaDisplayEvent`, `InvoiceTemplateMetaDefinitionEvent`

**UI and theme** — `ConfigureMainMenuEvent`, `PageActionsEvent`, `DashboardEvent`, `WizardEvent`,
`ThemeEvent`, `ThemeEventMenuStart`, `ThemeEventMenuEnd`, `ThemeJavascriptTranslationsEvent`

**Configuration and permissions** — `SystemConfigurationEvent`, `PermissionsEvent`,
`PermissionSectionsEvent`

**Reporting and export** — `ReportingEvent`, `ExportItemsQueryEvent`, `RevenueStatisticEvent`

**Calendar** — `CalendarConfigurationEvent`, `CalendarSourceEvent`, `CalendarGoogleSourceEvent`,
`CalendarDragAndDropSourceEvent`

**Email** — `EmailEvent`, `EmailPasswordResetEvent`, `EmailSelfRegistrationEvent`

**Working time and contracts** — `WorkingTimeApproveMonthEvent`, `WorkingTimeUnlockMonthEvent`,
`WorkingTimeYearEvent`, `WorkingTimeYearSummaryEvent`, `WorkingTimeQueryStatsEvent`,
`WorkContractDetailControllerEvent`

**Other** — `RecentActivityEvent`, `QuickEntryMetaDisplayEvent`

## UI extension recipes

### Menu entry

`MenuItemModel::__construct(string $id, string $label, ?string $route, array $routeArgs, ?string $icon)`.
Check the permission yourself — the menu does not do it for you.

```php
use App\Event\ConfigureMainMenuEvent;
use App\Utils\MenuItemModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class MenuSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuthorizationCheckerInterface $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConfigureMainMenuEvent::class => ['onMenuConfigure', 100]];
    }

    public function onMenuConfigure(ConfigureMainMenuEvent $event): void
    {
        if (!$this->security->isGranted('IS_AUTHENTICATED_REMEMBERED') || !$this->security->isGranted('view_foo')) {
            return;
        }

        $event->getMenu()->addChild(new MenuItemModel('foo', 'foo.title', 'foo', [], 'fas fa-plug'));
    }
}
```

### Dashboard widget

Extend `App\Widget\Type\AbstractWidget` (the docs' `AbstractWidgetType` is the older base class).
`WidgetInterface` carries `#[AutoconfigureTag]`, so no further registration is needed. Width and
height use the `WidgetInterface::WIDTH_*` / `HEIGHT_*` constants.

```php
use App\Widget\Type\AbstractWidget;
use App\Widget\WidgetInterface;

final class FooWidget extends AbstractWidget
{
    public function getId(): string { return 'FooWidget'; }
    public function getTitle(): string { return 'foo.widget'; }
    public function getTemplateName(): string { return '@Foo/widget.html.twig'; }
    public function getWidth(): int { return WidgetInterface::WIDTH_HALF; }
    public function getHeight(): int { return WidgetInterface::HEIGHT_MAXIMUM; }
    public function getPermissions(): array { return ['view_foo']; }

    public function getData(array $options = []): mixed
    {
        return ['items' => []];
    }

    public function getOptions(array $options = []): array
    {
        return array_merge(['id' => 'FooWidget'], parent::getOptions($options));
    }
}
```

Render it in a Twig template with `{{ render_widget('FooWidget') }}`.

### Theme injection (CSS, JS, extra HTML)

`ThemeEvent` constants: `HTML_HEAD`, `STYLESHEET`, `JAVASCRIPT`, `CONTENT_BEFORE`, `CONTENT_START`,
`TOOLBAR`, `CONTENT_END`, `CONTENT_AFTER`. Cheapest way to ship a small style or script without
asset installation.

```php
use App\Event\ThemeEvent;

public static function getSubscribedEvents(): array
{
    return [ThemeEvent::STYLESHEET => ['onStylesheet', 100]];
}

public function onStylesheet(ThemeEvent $event): void
{
    $event->addContent('<style>.foo { color: red; }</style>');
}
```

### Page action buttons

Extend `App\EventSubscriber\Actions\AbstractActionsSubscriber`; `getActionName()` matches the value a
controller sets via `PageSetup::setActionName()`, and `$event->getPayload()` carries whatever
`setActionPayload()` passed.

```php
use App\Event\PageActionsEvent;
use App\EventSubscriber\Actions\AbstractActionsSubscriber;

final class FooActionsSubscriber extends AbstractActionsSubscriber
{
    public static function getActionName(): string { return 'foo'; }

    public function onActions(PageActionsEvent $event): void
    {
        $event->addAction('sync', [
            'icon' => 'fas fa-sync',
            'title' => 'foo.sync',
            'url' => $this->path('foo_sync'),
        ]);
    }
}
```

### Reports

`Report::__construct(string $id, string $route, string $label, string $reportIcon, string $translationDomain = 'reporting')`.
The route's controller must check `view_reporting` itself.

```php
use App\Event\ReportingEvent;
use App\Reporting\Report;

public function onReporting(ReportingEvent $event): void
{
    if (!$this->security->isGranted('view_reporting')) {
        return;
    }

    $event->addReport(new Report('foo_report', 'foo_report', 'foo.report', 'fas fa-chart-bar'));
}
```

### Setup wizard

```php
use App\Event\WizardEvent;

public function onWizard(WizardEvent $event): void
{
    // core wizards: intro = 100, profile = 200
    $event->addWizard('foo_setup', 'foo_wizard_route');
}
```

## Configuration and permissions

### Registering permissions

Permissions only exist once the container knows about them, which happens at compile time — an
event is too late. Use `prepend()`:

```php
$container->prependExtensionConfig('kimai', [
    'permissions' => [
        'roles' => [
            'ROLE_SUPER_ADMIN' => ['view_foo', 'edit_foo'],
            'ROLE_TEAMLEAD' => ['view_foo'],
        ],
    ],
]);
```

Check them with `#[IsGranted('view_foo')]` on controllers or
`AuthorizationCheckerInterface::isGranted()` in services. Administrators can reassign them in the
user-role screen afterwards; what you declare here is only the default.

Group them under a heading in that screen with `PermissionSectionsEvent`:

```php
use App\Event\PermissionSectionsEvent;
use App\Model\PermissionSection;

public function onEvent(PermissionSectionsEvent $event): void
{
    $event->addSection(new PermissionSection('Foo', 'foo'));   // matches permissions containing "foo"
}
```

`PermissionsEvent` (`addPermissions(string $section, array $permissions)`,
`removePermission()`, `removeSection()`) lets you modify the whole permission map at runtime — use it
sparingly, it can silently disable core features.

### System configuration screen

Two halves: the `Configuration` tree in your extension defines names and defaults (see
`skeleton.md`), and this subscriber makes them editable. `App\Form\Model\Configuration` takes the
dotted key; the label comes from the translation domain you set.

```php
use App\Event\SystemConfigurationEvent;
use App\Form\Model\Configuration;
use App\Form\Model\SystemConfiguration;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

public function onSystemConfiguration(SystemConfigurationEvent $event): void
{
    $event->addConfiguration(
        (new SystemConfiguration('foo_config'))
            ->setConfiguration([
                (new Configuration('foo.api_url'))
                    ->setTranslationDomain('system-configuration')
                    ->setType(TextType::class),
                (new Configuration('foo.enabled'))
                    ->setTranslationDomain('system-configuration')
                    ->setType(CheckboxType::class),
            ])
    );
}
```

Values are persisted in the database and read back with
`App\Configuration\SystemConfiguration::find('foo.api_url')`. Do not store API secrets here — they
are stored and displayed in plain text.

### User preferences

`UserPreference::__construct(string $name, string|int|float|bool|null $value = null)`. Names are
sanitised and **must not contain dots**.

```php
use App\Entity\UserPreference;
use App\Event\UserPreferenceEvent;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;

public function loadUserPreferences(UserPreferenceEvent $event): void
{
    $event->addPreference(
        (new UserPreference('foo_enabled', false))
            ->setOrder(900)
            ->setType(CheckboxType::class)
            ->setEnabled(true)
            ->setSection('foo')
            ->setOptions(['label' => 'foo.pref', 'help' => 'foo.pref.help'])
    );
}
```

`UserPreferenceDisplayEvent` controls where a preference shows up in lists and exports.

## Data extension recipes

### Meta fields (custom fields)

The cleanest way to attach your own data to core entities without touching their tables. Define the
field for the form (`…MetaDefinitionEvent`) and for display/export (`…MetaDisplayEvent`) — usually the
same definition object, built once. Timesheet, customer, project, activity and invoice each have a
pair.

```php
use App\Entity\MetaTableTypeInterface;
use App\Entity\TimesheetMeta;
use App\Event\TimesheetMetaDefinitionEvent;
use App\Event\TimesheetMetaDisplayEvent;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Length;

public static function getSubscribedEvents(): array
{
    return [
        TimesheetMetaDefinitionEvent::class => ['loadMeta', 200],
        TimesheetMetaDisplayEvent::class => ['displayMeta', 200],
    ];
}

private function getMetaField(): MetaTableTypeInterface
{
    return (new TimesheetMeta())
        ->setName('foo_reference')
        ->setLabel('foo.reference')
        ->setType(TextType::class)
        ->setOptions(['label' => 'foo.reference'])
        ->addConstraint(new Length(max: 200))
        ->setIsVisible(true);
}

public function loadMeta(TimesheetMetaDefinitionEvent $event): void
{
    $event->getEntity()->setMetaField($this->getMetaField());
}

public function displayMeta(TimesheetMetaDisplayEvent $event): void
{
    $event->addField($this->getMetaField());
}
```

### Validation

Extend `App\Validator\Constraints\TimesheetConstraint` or `ProjectConstraint` (both tagged) and
supply a validator, to add rules that run wherever Kimai validates the entity — including the API and
imports, which a form-level check would miss.

## Auto-discovered interfaces

Implement, and Kimai finds it. All of these carry `#[AutoconfigureTag]`.

| Interface | Purpose | Key methods |
|---|---|---|
| `App\Widget\WidgetInterface` | dashboard widget | `getId`, `getTitle`, `getData`, `getTemplateName` |
| `App\Invoice\CalculatorInterface` | how invoice items are grouped and summed | `getId`, `calculate`-family |
| `App\Invoice\NumberGeneratorInterface` | invoice numbering scheme | `setModel`, `getInvoiceNumber`, `getId` |
| `App\Invoice\RendererInterface` | invoice output format | — |
| `App\Invoice\InvoiceModelHydrator` / `InvoiceItemHydrator` | extra placeholders in invoice templates | — |
| `App\Invoice\InvoiceItemRepositoryInterface` | add non-timesheet items to invoices | — |
| `App\Export\RendererInterface` | export format in the export screen | `render`, `getId`, `getTitle`, `getIcon` |
| `App\Export\TimesheetExportInterface` | export format for the timesheet screen | `render`, `getId`, `getTitle` |
| `App\Export\ExportRepositoryInterface` | additional export data source | — |
| `App\Timesheet\CalculatorInterface` | recalculate a stopped record (duration, rate) | `calculate(Timesheet, array $changeset)`, `getPriority` |
| `App\Timesheet\Rounding\RoundingInterface` | rounding strategy | — |
| `App\WorkingTime\Mode\WorkingTimeMode` | working-time calculation model | — |
| `App\Validator\Constraints\TimesheetConstraint` / `ProjectConstraint` | validation rules | — |

`App\Timesheet\TrackingMode\TrackingModeInterface` is tagged too, but its docblock explicitly asks you
not to implement it in a plugin and to send a core PR instead.

Invoice calculators and number generators need translated names in
`Resources/translations/invoice-calculator.<locale>.xlf` and
`invoice-numbergenerator.<locale>.xlf`, keyed by the ID you return.

Extra invoice or export template directories are registered by prepending config, not by an
interface:

```php
$container->prependExtensionConfig('kimai', [
    'invoice' => ['documents' => ['var/plugins/FooBundle/Resources/invoices/']],
    'export' => ['documents' => ['var/plugins/FooBundle/Resources/export/']],
]);
```

## API endpoints

Kimai's API uses FOSRestBundle plus NelmioApiDocBundle. Put controllers in `API/`, import them in
`routes.yaml` with `prefix: /api`, and guard them with `#[IsGranted('API')]` — that is the permission
that gates API access as a whole; add your own on top for the specific resource.

```php
use FOS\RestBundle\View\View;
use FOS\RestBundle\View\ViewHandlerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/foos')]
#[OA\Tag(name: 'Foo')]
#[IsGranted('API')]
final class FooController extends AbstractController
{
    public function __construct(private readonly ViewHandlerInterface $viewHandler)
    {
    }

    #[OA\Response(response: 200, description: 'Returns foos', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/FooEntity')))]
    #[Route(methods: ['GET'])]
    public function cgetAction(): Response
    {
        $view = new View([], 200);
        $view->getContext()->setGroups(['Default', 'Collection', 'Foo']);

        return $this->viewHandler->handle($view);
    }
}
```

Serialization uses JMS annotations on the model (`#[Serializer\ExclusionPolicy('all')]`,
`#[Serializer\Expose]`, `#[Serializer\Groups([...])]`). Register the model with Nelmio and warm up JMS
metadata from `prepend()`:

```php
$container->prependExtensionConfig('nelmio_api_doc', [
    'models' => ['names' => [
        ['alias' => 'FooEntity', 'type' => FooEntity::class, 'groups' => ['Default', 'Entity', 'Foo']],
    ]],
]);

$container->prependExtensionConfig('jms_serializer', [
    'metadata' => ['warmup' => ['paths' => ['included' => [__DIR__ . '/../Entity/']]]],
]);
```

Swagger UI needs `DEFAULT_URI` set correctly in `.env` (scheme, host and port) or the generated
endpoint URLs are wrong.

## Hooks for integration and sync plugins

For plugins that push Kimai data into an external system, the useful combination is:

- `TimesheetUpdatePostEvent` / `TimesheetCreatePostEvent` / `TimesheetDeletePreEvent` to notice
  changes. Take the "post" variants for anything that must only happen after a successful save.
- `InvoiceCreatedEvent` for invoice-driven flows.
- A meta field (`foo_external_id`) or your own `kimai2_ext_*` mapping table to remember what has been
  synced. Prefer the mapping table when you need timestamps, retry counts or error state.
- An `#[AsCommand]` console command run by cron for batch sync, so a failing remote API never blocks
  a user saving a timesheet.
- `symfony/http-client` (`HttpClientInterface`) for the outbound calls — it is already in Kimai's
  vendor directory.
- `SystemConfigurationEvent` for endpoint URLs and toggles; environment variables referenced from
  `local.yaml` for tokens and secrets.

Doing the network call inside the event listener is the classic mistake here: it makes every
timesheet save depend on a third party being up. Record the intent synchronously, transmit
asynchronously.

## What you cannot extend

- **Core database tables.** Migrations must not alter them; an update will fight you. Use meta fields
  or your own tables.
- **Third-party libraries.** No plugin autoloader, so no Composer dependencies of your own.
- **`TrackingModeInterface`** — tagged, but core asks for a PR instead.
- **Frontend build.** Webpack Encore compiles `assets/` in core only. Plugins ship plain CSS/JS via
  `Resources/public/` or a `ThemeEvent`.
- **Custom translation file locations** — since 2.0 only `Resources/translations/` inside your bundle.
- **Stable APIs across minor versions.** There is no BC promise for plugins; pin
  `extra.kimai.require` and retest on upgrades.

<?php
declare(strict_types=1);

namespace Jadob\Dashboard\Action;

use Closure;
use DateTimeInterface;
use Jadob\Contracts\Dashboard\DashboardContextInterface;
use Jadob\Dashboard\ActionType;
use Jadob\Dashboard\Configuration\Dashboard;
use Jadob\Dashboard\Configuration\DashboardConfiguration;
use Jadob\Dashboard\Configuration\NewObjectConfiguration;
use Jadob\Dashboard\CrudOperationType;
use Jadob\Dashboard\Exception\DashboardException;
use Jadob\Dashboard\ObjectManager\DoctrineOrmObjectManager;
use Jadob\Dashboard\OperationHandler;
use Jadob\Dashboard\PathGenerator;
use Jadob\Dashboard\QueryStringParamName;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\File;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class DashboardAction
{

    protected Environment $twig;
    protected DashboardConfiguration $configuration;
    protected DoctrineOrmObjectManager $doctrineOrmObjectManager;
    protected FormFactoryInterface $formFactory;
    protected PathGenerator $pathGenerator;
    protected OperationHandler $operationHandler;
    protected LoggerInterface $logger;

    public function __construct(
        Environment $twig,
        DashboardConfiguration $configuration,
        DoctrineOrmObjectManager $doctrineOrmObjectManager,
        FormFactoryInterface $formFactory,
        PathGenerator $pathGenerator,
        OperationHandler $operationHandler,
        LoggerInterface $logger
    )
    {
        $this->twig = $twig;
        $this->configuration = $configuration;
        $this->doctrineOrmObjectManager = $doctrineOrmObjectManager;
        $this->formFactory = $formFactory;
        $this->pathGenerator = $pathGenerator;
        $this->operationHandler = $operationHandler;
        $this->logger = $logger;
    }

    /**
     * @param Request $request
     * @param DashboardContextInterface $context
     * @return Response
     * @throws DashboardException
     * @throws LoaderError
     * @throws ReflectionException
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function __invoke(
        Request $request,
        DashboardContextInterface $context
    ): Response
    {
        $action = $request->query->get(QueryStringParamName::ACTION);

        if ($action === null) {
            return $this->handleDashboard(
                $this->configuration->getDefaultDashboard(),
                $this->configuration,
                $context,
                $request
            );
        }

        $action === mb_strtolower($action);

        if ($action === ActionType::CRUD) {
            return $this->handleCrudOperation($request);
        }

        if ($action === ActionType::IMPORT) {
            return $this->handleImport($request);
        }

        if ($action === ActionType::OPERATION) {
            return $this->handleOperation($request, $context);
        }
    }

    /**
     * @param Request $request
     * @return Response
     * @throws ReflectionException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws DashboardException
     */
    protected function handleCrudOperation(Request $request): Response
    {
        $operation = mb_strtolower($request->query->get(QueryStringParamName::CRUD_OPERATION));
        $objectFqcn = $request->query->get(QueryStringParamName::OBJECT_NAME);
        $managedObjectConfiguration = $this->configuration->getManagedObjectConfiguration($objectFqcn);

        if ($operation === CrudOperationType::LIST) {
            $listConfiguration = $managedObjectConfiguration->getListConfiguration();
            if (count($listConfiguration->getFieldsToShow()) === 0) {
                throw new DashboardException(sprintf('There is no fields to show in "%s" object configuration.', $objectFqcn));
            }

            $pageNumber = $request->query->getInt(QueryStringParamName::CRUD_CURRENT_PAGE, 1);
            $resultsPerPage = $listConfiguration->getResultsPerPage();
            $pagesCount = $this->doctrineOrmObjectManager->getPagesCount($objectFqcn, $resultsPerPage);

            $objects = $this->doctrineOrmObjectManager->read(
                $objectFqcn,
                $pageNumber,
                $resultsPerPage
            );

            $list = [];
            $fieldsToExtract = $listConfiguration->getFieldsToShow();

            foreach ($objects as $object) {
                $objectArray = [];
                $reflectionObject = new ReflectionClass($object);

                foreach ($fieldsToExtract as $fieldToExtract) {
                    $prop = $reflectionObject->getProperty($fieldToExtract);
                    $prop->setAccessible(true);
                    $val = $prop->getValue($object);

                    if ($val instanceof DateTimeInterface) {
                        $val = $val->format('Y-m-d H:i:s');
                    }

                    $objectArray[$fieldToExtract] = $val;
                }

                $list[] = $objectArray;
            }


            return new Response(
                $this->twig->render(
                    '@JadobDashboard/crud/list.html.twig', [
                        'managed_object' => $managedObjectConfiguration,
                        'dashboard_config' => $this->configuration,
                        'list' => $list,
                        'fields' => $fieldsToExtract,
                        'object_fqcn' => $objectFqcn,
                        'results_per_page' => $resultsPerPage,
                        'current_page' => $pageNumber,
                        'pages_count' => $pagesCount,
                        'operations' => $listConfiguration->getOperations()
                    ]
                )
            );
        }

        if ($operation === CrudOperationType::NEW || $operation === CrudOperationType::EDIT) {
            $isEdit = $operation === CrudOperationType::EDIT;

            $objectConfig = $this->configuration->getManagedObjectConfiguration($objectFqcn);
            if (!$objectConfig->hasNewObjectConfiguration()) {
                throw new DashboardException(
                    sprintf('Object "%s" does not have configuration for new objects.', $objectFqcn)
                );
            }

            /** @var NewObjectConfiguration $newConfiguration */
            $newConfiguration = $objectConfig->getNewObjectConfiguration();
            $formBuilder = $newConfiguration->getFormFactory();

            /** @var FormInterface $form */
            $form = $formBuilder($this->formFactory);

            if($isEdit) {
                $objectId = $request->query->get(QueryStringParamName::OBJECT_ID);
                $object = $this->doctrineOrmObjectManager->getOneById($objectFqcn, $objectId);
                if($object === null) {
                    throw new DashboardException(sprintf('There is no object "%s" with ID "%s"!', $objectFqcn, $objectId));
                }

                $form->setData($object);
            }

            if ($form === null) {
                throw new RuntimeException('Form factory does not returned a Form!');
            }

            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $createdObject = $form->getData();

                if ($newConfiguration->hasBeforeInsertHook()) {
                    $newConfiguration->getBeforeInsertHook()($createdObject);
                }

                $this->doctrineOrmObjectManager->persist($createdObject);
                return new RedirectResponse($this->pathGenerator->getPathForObjectList($objectFqcn));
            }

            return new Response(
                $this->twig->render(
                    '@JadobDashboard/crud/new.html.twig', [
                        'dashboard_config' => $this->configuration,
                        'form' => $form->createView(),
                        'object_fqcn' => $objectFqcn,
                    ]
                )
            );
        }

        throw new RuntimeException('JDASH0003: Not implemented yet');
    }

    /**
     * @param Dashboard $dashboard
     * @param DashboardConfiguration $dashboardConfiguration
     * @param DashboardContextInterface $context
     * @param Request $request
     * @return Response
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    protected function handleDashboard(
        Dashboard $dashboard,
        DashboardConfiguration $dashboardConfiguration,
        DashboardContextInterface $context,
        Request $request
    ): Response
    {

        $requestDate = $context->getRequestDateTime();

        return new Response(
            $this->twig->render(
                '@JadobDashboard/dashboard.html.twig', [
                    'dashboard_name' => sprintf('dashboard-%s', $dashboard->getName()),
                    'dashboard_config' => $dashboardConfiguration,
                    'dashboard' => $dashboard,
                    'request_date' => $requestDate,
                    'request' => $request
                ]
            )
        );
    }

    protected function handleImport(Request $request): Response
    {
        $objectFqcn = $request->query->get(QueryStringParamName::OBJECT_NAME);
        $managedObjectConfiguration = $this->configuration->getManagedObjectConfiguration($objectFqcn);

        if (!isset($managedObjectConfiguration['imports']) || count($managedObjectConfiguration['imports']) === 0) {
            throw new RuntimeException('There is no import configured for this managed object.');
        }

        $imports = $managedObjectConfiguration['imports'];

        $forms = [];
        foreach ($imports as $key => $import) {
            if ($import['type'] === 'csv_upload') {
                $form['title'] = $import['name'];
                $form['name'] = $key;
                $formObject = $this
                    ->formFactory
                    ->createNamedBuilder($key)
                    ->add('file', FileType::class, [
                        'constraints' => [
                            new File(['mimeTypes' => $import['mime']])
                        ]
                    ])
                    ->add('submit', SubmitType::class)
                    ->getForm();

                $formObject->handleRequest($request);
                if ($formObject->isSubmitted() && $formObject->isValid()) {
                    $this->logger->info('Form submitted, proceeding to process file.');
                    /** @var UploadedFile $uploadedFile */
                    $uploadedFile = $formObject->get('file')->getData();
                    $fileHandler = $uploadedFile->openFile();
                    $firstLine = true;
                    $headers = [];
                    foreach ($fileHandler as $line) {
                        if ($firstLine) {
                            $firstLine = false;
                            $headers = array_flip(str_getcsv($line));
                            $this->logger->info('Found headers in uploaded file.', [
                                'headers' => $headers
                            ]);

                            continue;
                        }

                        $reflectionObject = new ReflectionClass($objectFqcn);
                        $instance = $reflectionObject->newInstanceWithoutConstructor();
                        $csvLine = str_getcsv($line);

                        if (count($csvLine) !== count($headers)) {
                            $this->logger->warning('Error while importing a file: line does not matches headers, line will be skipped.', [
                                'line' => $csvLine,
                                'headers' => $headers
                            ]);
                            continue;
                        }

                        foreach ($import['mapping'] as $csvHeader => $property) {
                            $valueToInsert = $csvLine[$headers[$csvHeader]];
                            $reflectionProp = $reflectionObject->getProperty($property);
                            $reflectionProp->setAccessible(true);
                            $reflectionProp->setValue($instance, $valueToInsert);
                        }

                        if (isset($import['before_insert'])) {
                            if (!($import['before_insert'] instanceof Closure)) {
                                throw new RuntimeException('Could not use before_insert hook as it is not a closure!');
                            }

                            $import['before_insert']($instance);
                        }

                        $this->doctrineOrmObjectManager->persist($instance);

                        if (isset($import['post_insert'])) {
                            if (!($import['post_insert'] instanceof Closure)) {
                                throw new RuntimeException('Could not use before_insert hook as it is not a closure!');
                            }

                            $import['post_insert']($instance);
                        }
                    }

                    $this->logger->info('Finished processing file, redirecting to listing page.');
                    return new RedirectResponse($this->pathGenerator->getPathForObjectList($objectFqcn));
                }

                $form['form'] = $formObject;
                $forms[] = $form;
            }

            if ($import['type'] === 'paste_csv') {

                $form['title'] = $import['name'];
                $form['name'] = $key;
                $formObject = $this
                    ->formFactory
                    ->createNamedBuilder($key)
                    ->add('content', TextareaType::class, [
                    ])
                    ->add('submit', SubmitType::class)
                    ->getForm();

                $formObject->handleRequest($request);
                if ($formObject->isSubmitted() && $formObject->isValid()) {


                    $this->logger->info('Form submitted, proceeding to handle upload.');
                    $content = $formObject->get('content')->getData();
                    $splittedContent = explode(PHP_EOL, $content);

                    $mapping = $import['mapping'];
                    $headers = [];
                    foreach ($splittedContent as $line) {
                        $reflectionObject = new ReflectionClass($objectFqcn);
                        $instance = $reflectionObject->newInstanceWithoutConstructor();
                        $csvLine = str_getcsv($line, $import['separator'] ?? ',');


                        if (count($csvLine) !== count($mapping)) {
                            $this->logger->warning('Error while importing a file: line does not matches headers, line will be skipped.', [
                                'line' => $csvLine,
                                'headers' => $headers
                            ]);
                            continue;
                        }

                        foreach ($mapping as $csvHeader => $property) {
                            $valueToInsert = $csvLine[$csvHeader];
                            $reflectionProp = $reflectionObject->getProperty($property);
                            $reflectionProp->setAccessible(true);
                            $reflectionProp->setValue($instance, $valueToInsert);
                        }

                        if (isset($import['before_insert'])) {
                            if (!($import['before_insert'] instanceof Closure)) {
                                throw new RuntimeException('Could not use before_insert hook as it is not a closure!');
                            }

                            $import['before_insert']($instance);
                        }

                        $this->doctrineOrmObjectManager->persist($instance);

                        if (isset($import['post_insert'])) {
                            if (!($import['post_insert'] instanceof Closure)) {
                                throw new RuntimeException('Could not use before_insert hook as it is not a closure!');
                            }

                            $import['post_insert']($instance);
                        }
                    }

                    $this->logger->info('Finished processing uploaded content, redirecting to listing page.');
                    return new RedirectResponse($this->pathGenerator->getPathForObjectList($objectFqcn));
                }

                $form['form'] = $formObject;
                $forms[] = $form;
            }
        }

        return new Response(
            $this->twig->render(
                '@JadobDashboard/import.html.twig', [
                    'object_fqcn' => $objectFqcn,
                    'dashboard_config' => $this->configuration,
                    'forms' => $forms
                ]
            )
        );

    }

    public function handleOperation(Request $request, DashboardContextInterface $context)
    {
        $this->logger->debug('handleOperation invoked');
        $objectFqcn = $request->query->get(QueryStringParamName::OBJECT_NAME);
        $objectId = $request->query->get(QueryStringParamName::OBJECT_ID);
        $operationName = $request->query->get(QueryStringParamName::OPERATION_NAME);
        $managedObjectConfiguration = $this->configuration->getManagedObjectConfiguration($objectFqcn);

        $this->logger->debug('Getting information about operation');
        $operation = $managedObjectConfiguration->getListConfiguration()->getOperation($operationName);
        $this->logger->debug('Getting object from persistence');
        $object = $this->doctrineOrmObjectManager->getOneById($objectFqcn, $objectId);
        $this->logger->debug(sprintf('Continuing to invoke an operation "%s"', $operationName));
        $this->operationHandler->processOperation($operation, $object, $context);
        $this->logger->debug(sprintf('Operation "%s" invoked, returning to list view.', $operationName));

        return new RedirectResponse($this->pathGenerator->getPathForObjectList($objectFqcn));

    }
}
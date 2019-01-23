<?php declare(strict_types=1);

namespace Shopware\Core\Content\Test\Media\File;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Media\Exception\CouldNotRenameFileException;
use Shopware\Core\Content\Media\Exception\DuplicatedMediaFileNameException;
use Shopware\Core\Content\Media\Exception\MediaNotFoundException;
use Shopware\Core\Content\Media\Exception\MissingFileException;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaProtectionFlags;
use Shopware\Core\Content\Media\Metadata\MetadataLoader;
use Shopware\Core\Content\Media\Pathname\UrlGeneratorInterface;
use Shopware\Core\Content\Media\Thumbnail\ThumbnailService;
use Shopware\Core\Content\Media\TypeDetector\TypeDetector;
use Shopware\Core\Content\Test\Media\MediaFixtures;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Struct\Uuid;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

class FileSaverTest extends TestCase
{
    use IntegrationTestBehaviour, MediaFixtures;

    public const TEST_IMAGE = __DIR__ . '/../fixtures/shopware-logo.png';

    /**
     * @var EntityRepositoryInterface
     */
    private $mediaRepository;

    /**
     * @var FileSaver
     */
    private $fileSaver;

    /** @var UrlGeneratorInterface */
    private $urlGenerator;

    public function setUp()
    {
        $this->mediaRepository = $this->getContainer()->get('media.repository');
        $this->urlGenerator = $this->getContainer()->get(UrlGeneratorInterface::class);
        $this->fileSaver = $this->getContainer()->get(FileSaver::class);
    }

    public function testPersistFileToMediaHappyPathForInitialUpload(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), '');
        copy(self::TEST_IMAGE, $tempFile);

        $fileSize = filesize($tempFile);
        $mediaFile = new MediaFile($tempFile, 'image/png', 'png', $fileSize);

        $mediaId = Uuid::uuid4()->getHex();

        $context = Context::createDefaultContext();
        $context->getWriteProtection()->allow(MediaProtectionFlags::WRITE_META_INFO);

        $this->mediaRepository->create(
            [
                [
                    'id' => $mediaId,
                    'name' => 'test file',
                ],
            ],
            $context
        );

        try {
            $this->fileSaver->persistFileToMedia(
                $mediaFile,
                'test-file',
                $mediaId,
                $context
            );
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        $media = $this->mediaRepository->search(new Criteria([$mediaId]), $context)->get($mediaId);
        $path = $this->urlGenerator->getRelativeMediaUrl($media);
        static::assertTrue($this->getPublicFilesystem()->has($path));
    }

    public function testPersistFileToMediaRemovesOldFile(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), '');
        copy(self::TEST_IMAGE, $tempFile);

        $fileSize = filesize($tempFile);
        $mediaFile = new MediaFile($tempFile, 'image/png', 'png', $fileSize);

        $context = Context::createDefaultContext();
        $context->getWriteProtection()->allow(MediaProtectionFlags::WRITE_META_INFO);

        $this->setFixtureContext($context);
        $media = $this->getTxt();

        $oldMediaFilePath = $this->urlGenerator->getRelativeMediaUrl($media);
        $this->getPublicFilesystem()->put($oldMediaFilePath, 'Some ');

        try {
            $this->fileSaver->persistFileToMedia(
                $mediaFile,
                $media->getFileName(),
                $media->getId(),
                $context
            );
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
        $media = $this->mediaRepository->search(new Criteria([$media->getId()]), $context)->get($media->getId());

        $path = $this->urlGenerator->getRelativeMediaUrl($media);
        static::assertNotEquals($oldMediaFilePath, $path);
        static::assertTrue($this->getPublicFilesystem()->has($path));
        static::assertFalse($this->getPublicFilesystem()->has($oldMediaFilePath));
    }

    public function testPersistFileToMediaForMediaTypeWithoutThumbs(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), '');
        copy(__DIR__ . '/../fixtures/reader.doc', $tempFile);

        $fileSize = filesize($tempFile);
        $mediaFile = new MediaFile($tempFile, 'application/doc', 'doc', $fileSize);

        $mediaId = Uuid::uuid4()->getHex();

        $context = Context::createDefaultContext();
        $context->getWriteProtection()->allow(MediaProtectionFlags::WRITE_META_INFO);

        $this->mediaRepository->create(
            [
                [
                    'id' => $mediaId,
                    'name' => 'test file',
                ],
            ],
            $context
        );

        try {
            $this->fileSaver->persistFileToMedia(
                $mediaFile,
                'test-file',
                $mediaId,
                $context
            );
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        $media = $this->mediaRepository->search(new Criteria([$mediaId]), $context)->get($mediaId);
        $path = $this->urlGenerator->getRelativeMediaUrl($media);
        static::assertTrue($this->getPublicFilesystem()->has($path));
    }

    public function testPersistFileToMediaDoesNotAddSuffixOnReplacement(): void
    {
        $context = Context::createDefaultContext();
        $context->getWriteProtection()->allow(MediaProtectionFlags::WRITE_META_INFO);

        $this->setFixtureContext($context);
        $png = $this->getPng();

        $tempFile = tempnam(sys_get_temp_dir(), '');
        copy(self::TEST_IMAGE, $tempFile);
        $mediaFile = new MediaFile($tempFile, 'image/png', 'png', filesize($tempFile));

        $pathName = $this->urlGenerator->getRelativeMediaUrl($png);
        $this->getPublicFilesystem()->putStream($pathName, fopen($tempFile, 'r'));

        try {
            $this->fileSaver->persistFileToMedia(
                $mediaFile,
                $png->getFileName(),
                $png->getId(),
                $context
            );
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        $updatedMedia = $this->mediaRepository->search(new Criteria([$png->getId()]), $context)->get($png->getId());
        self::assertStringEndsWith($png->getFileName(), $updatedMedia->getFileName());
    }

    public function testPersistFileToMediaAddsSuffixIfFileNameIsInUse(): void
    {
        $context = Context::createDefaultContext();
        $context->getWriteProtection()->allow(MediaProtectionFlags::WRITE_META_INFO);

        $this->setFixtureContext($context);
        $png = $this->getPng();

        $newMediaId = Uuid::uuid4()->getHex();
        $tempFile = tempnam(sys_get_temp_dir(), '');
        copy(self::TEST_IMAGE, $tempFile);
        $mediaFile = new MediaFile($tempFile, 'image/png', 'png', filesize($tempFile));

        try {
            $this->mediaRepository->create(
                [
                    [
                        'id' => $newMediaId,
                        'name' => 'test_media',
                    ],
                ],
                $context
            );

            $this->fileSaver->persistFileToMedia(
                $mediaFile,
                $png->getFileName(),
                $newMediaId,
                $context
            );
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        $newMedia = $this->mediaRepository->search(new Criteria([$newMediaId]), $context)->get($newMediaId);
        self::assertStringEndsWith($png->getFileName() . ' (1)', $newMedia->getFileName());
    }

    public function testPersistFileToMediaWorksWithMoreThan255Characters(): void
    {
        $longFileName = '';
        while (strlen($longFileName) < 512) {
            $longFileName .= 'Word';
        }

        $context = Context::createDefaultContext();
        $context->getWriteProtection()->allow(MediaProtectionFlags::WRITE_META_INFO);

        $this->setFixtureContext($context);
        $png = $this->getPng();

        $tempFile = tempnam(sys_get_temp_dir(), '');
        copy(self::TEST_IMAGE, $tempFile);
        $mediaFile = new MediaFile($tempFile, 'image/png', 'png', filesize($tempFile));
        $this->getPublicFilesystem()->put($this->urlGenerator->getRelativeMediaUrl($png), 'some content');

        try {
            $this->fileSaver->persistFileToMedia(
                $mediaFile,
                $longFileName,
                $png->getId(),
                $context
            );
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        $updated = $this->mediaRepository->search(new Criteria([$png->getId()]), $context)->get($png->getId());
        static::assertStringEndsWith($longFileName, $updated->getFileName());
        static::assertTrue($this->getPublicFilesystem()->has($this->urlGenerator->getRelativeMediaUrl($updated)));
    }

    public function testRenameMediaThrowsExceptionIfMediaDoesNotExist(): void
    {
        static::expectException(MediaNotFoundException::class);

        $context = Context::createDefaultContext();
        $this->fileSaver->renameMedia(Uuid::uuid4()->getHex(), 'new file destination', $context);
    }

    public function testRenameMediaThrowsExceptionIfMediaHasNoFileAttached()
    {
        static::expectException(MissingFileException::class);

        $context = Context::createDefaultContext();
        $id = Uuid::uuid4()->getHex();

        $this->mediaRepository->create([
            [
                'id' => $id,
                'name' => 'testFileWithNoData',
            ],
        ], $context);

        $this->fileSaver->renameMedia($id, 'new destination', $context);
    }

    public function testRenameMediaThrowsExceptionIfFileNameAlreadyExists(): void
    {
        static::expectException(DuplicatedMediaFileNameException::class);

        $context = Context::createDefaultContext();
        $context->getWriteProtection()->allow(MediaProtectionFlags::WRITE_META_INFO);
        $this->setFixtureContext($context);

        $png = $this->getPng();
        $txt = $this->getTxt();

        $this->fileSaver->renameMedia($txt->getId(), $png->getFileName(), $context);
    }

    public function testRenameMediaDoesSkipIfOldFileNameEqualsNewOne(): void
    {
        $context = Context::createDefaultContext();
        $context->getWriteProtection()->allow(MediaProtectionFlags::WRITE_META_INFO);
        $context->getWriteProtection()->allow(MediaProtectionFlags::WRITE_THUMBNAILS);
        $this->setFixtureContext($context);

        $png = $this->getPng();
        $mediaPath = $this->urlGenerator->getRelativeMediaUrl($png);
        $this->getPublicFilesystem()->put($mediaPath, 'test file content');

        $this->fileSaver->renameMedia($png->getId(), $png->getFileName(), $context);
        static::assertTrue($this->getPublicFilesystem()->has($mediaPath));
    }

    public function testRenameMediaRenamesOldFileAndThumbnails(): void
    {
        $context = Context::createDefaultContext();
        $context->getWriteProtection()->allow(MediaProtectionFlags::WRITE_META_INFO);
        $context->getWriteProtection()->allow(MediaProtectionFlags::WRITE_THUMBNAILS);
        $this->setFixtureContext($context);

        $png = $this->getPng();
        $this->mediaRepository->update([[
            'id' => $png->getId(),
            'thumbnails' => [
                [
                    'width' => 100,
                    'height' => 100,
                    'highDpi' => false,
                ],
            ],
        ]], $context);
        $oldMediaPath = $this->urlGenerator->getRelativeMediaUrl($png);
        $oldThumbnailPath = $this->urlGenerator->getRelativeThumbnailUrl($png, 100, 100);

        $this->getPublicFilesystem()->put($oldMediaPath, 'test file content');
        $this->getPublicFilesystem()->put($oldThumbnailPath, 'test file content');

        $this->fileSaver->renameMedia($png->getId(), 'new destination', $context);
        $updatedMedia = $this->mediaRepository->search(new Criteria([$png->getId()]), $context)->get($png->getId());
        static::assertFalse($this->getPublicFilesystem()->has($oldMediaPath));
        static::assertTrue($this->getPublicFilesystem()->has($this->urlGenerator->getRelativeMediaUrl($updatedMedia)));

        static::assertFalse($this->getPublicFilesystem()->has($oldThumbnailPath));
        static::assertTrue($this->getPublicFilesystem()->has($this->urlGenerator->getRelativeThumbnailUrl($updatedMedia, 100, 100)));
    }

    public function testRenameMediaMakesRollbackOnFailure(): void
    {
        static::expectException(CouldNotRenameFileException::class);

        $context = Context::createDefaultContext();
        $context->getWriteProtection()->allow(MediaProtectionFlags::WRITE_META_INFO);
        $this->setFixtureContext($context);
        $png = $this->getPng();

        $searchResult = new EntitySearchResult(1, new MediaCollection([$png]), null, new Criteria(), $context);

        $repositoryMock = $this->createMock(EntityRepository::class);
        $repositoryMock->expects($this->once())
            ->method('search')
            ->willReturn($searchResult);

        $repositoryMock->expects($this->once())
            ->method('update')
            ->willThrowException(new \Exception());

        $fileSaverWithFailingRepository = new FileSaver(
            $repositoryMock,
            $this->getContainer()->get('shopware.filesystem.public'),
            $this->getContainer()->get(UrlGeneratorInterface::class),
            $this->getContainer()->get(ThumbnailService::class),
            $this->getContainer()->get(MetadataLoader::class),
            $this->getContainer()->get(TypeDetector::class)
        );

        $mediaPath = $this->urlGenerator->getRelativeMediaUrl($png);
        $this->getPublicFilesystem()->put($mediaPath, 'test file');

        $fileSaverWithFailingRepository->renameMedia($png->getId(), 'new file name', $context);
        $updatedMedia = $this->mediaRepository->search(new Criteria([$png->getId()]), $context)->get($png->getId());

        static::assertEquals($png->getFileName(), $updatedMedia->getFileName());
        static::assertTrue($this->getPublicFilesystem()->has($mediaPath));
    }
}

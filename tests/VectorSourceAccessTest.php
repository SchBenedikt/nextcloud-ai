<?php
declare(strict_types=1);
namespace OCA\EvaAi\Tests;

use OCA\EvaAi\Vector\{VectorSourceAccess, VectorStoreException};
use OCA\EvaAi\Service\{EmailService, TalkTranscriptService, Indexer};
use OCP\{IUserManager,IUser};
use OCP\App\IAppManager;
use OCP\Files\{IRootFolder,Folder,File};
use PHPUnit\Framework\TestCase;

final class VectorSourceAccessTest extends TestCase {
    protected function setUp():void {
        if (!defined('EVA_AI_OCP_AVAILABLE') || !EVA_AI_OCP_AVAILABLE) self::markTestSkipped('Nextcloud interfaces required.');
    }
    private function fixture(bool $enabled=true):array {
        $user=$this->createMock(IUser::class);$user->method('getUID')->willReturn('alice');$user->method('isEnabled')->willReturn($enabled);
        $users=$this->createMock(IUserManager::class);$users->method('get')->with('alice')->willReturn($user);
        $apps=$this->createMock(IAppManager::class);$apps->method('isEnabledForUser')->willReturn(true);
        $files=$this->createMock(IRootFolder::class);$mail=$this->createMock(EmailService::class);$talk=$this->createMock(TalkTranscriptService::class);
        $access=new VectorSourceAccess($users,$apps,$files,$mail,$talk);
        return compact('access','files','mail','talk','apps','user');
    }
    public function testDisabledOwnerCannotAuthorizeTransfers():void {
        $f=$this->fixture(false);$f['files']->expects(self::never())->method('getUserFolder');
        $this->expectException(VectorStoreException::class);$f['access']->assertOwner('alice');
    }
    public function testCurrentReadableFileUsesNativeLeadingSlashRelativePaths():void {
        $f=$this->fixture();$file=$this->createMock(File::class);$file->method('isReadable')->willReturn(true);
        $file->method('getPath')->willReturn('/alice/files/Notes/a.txt');$file->method('getMTime')->willReturn(11);$file->method('getSize')->willReturn(12);
        $home=$this->createMock(Folder::class);$home->method('getById')->with(8)->willReturn([$file]);$home->method('getRelativePath')->willReturn('/Notes/a.txt');
        $f['files']->method('getUserFolder')->with('alice')->willReturn($home);
        $f['access']->assertAccessible('alice',['source'=>'files','file_id'=>8,'path'=>'Notes/a.txt','source_mtime'=>11,'source_size'=>12]);
        self::assertTrue(true);
    }
    public function testRenamedFileIsRejectedBeforeStaleScopeMetadataCanLeave():void {
        $f=$this->fixture();$file=$this->createMock(File::class);$file->method('isReadable')->willReturn(true);$file->method('getPath')->willReturn('/alice/files/Other/a.txt');
        $home=$this->createMock(Folder::class);$home->method('getById')->willReturn([$file]);$home->method('getRelativePath')->willReturn('/Other/a.txt');$f['files']->method('getUserFolder')->willReturn($home);
        $this->expectException(VectorStoreException::class);
        $f['access']->assertAccessible('alice',['source'=>'files','file_id'=>8,'path'=>'Notes/a.txt','source_mtime'=>11,'source_size'=>12]);
    }
    public function testMailChecksOwnershipWithoutFetchingAnImapBody():void {
        $f=$this->fixture();$f['mail']->expects(self::once())->method('messageAccessible')->with('alice',42)->willReturn(true);
        $f['mail']->expects(self::never())->method('bodyText');
        $f['access']->assertAccessible('alice',['source'=>'mail','file_id'=>-42,'path'=>'mail://42']);self::assertTrue(true);
    }
    public function testTalkRequiresCurrentNativeMembership():void {
        $f=$this->fixture();$f['talk']->expects(self::once())->method('isMember')->with('alice',5)->willReturn(false);
        $this->expectException(VectorStoreException::class);
        $f['access']->assertAccessible('alice',['source'=>'talk','file_id'=>Indexer::talkFileId(5),'path'=>'talk://5']);
    }
}

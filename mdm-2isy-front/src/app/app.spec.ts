import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import { App } from './app';

describe('App', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [provideHttpClient(), provideRouter([])],
    }).compileComponents();
  });

  it('should create the app', () => {
    const fixture = TestBed.createComponent(App);
    const app = fixture.componentInstance;
    expect(app).toBeTruthy();
  });

  it('should render the login outlet without the application sidebar', () => {
    const fixture = TestBed.createComponent(App);
    fixture.componentInstance.isLoginPage = true;
    fixture.detectChanges();
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('router-outlet')).toBeTruthy();
    expect(compiled.querySelector('app-sidebar')).toBeNull();
  });

  it('should open and close the mobile navigation drawer', () => {
    const fixture = TestBed.createComponent(App);
    const app = fixture.componentInstance;

    expect(app.menuOpen).toBe(false);
    app.toggleMenu();
    expect(app.menuOpen).toBe(true);
    app.closeMenu();
    expect(app.menuOpen).toBe(false);
  });
});
